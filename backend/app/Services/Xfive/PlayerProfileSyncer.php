<?php

namespace App\Services\Xfive;

use App\Models\Competition;
use App\Models\Player;
use App\Models\PlayerCompetitionStat;
use App\Models\Team;
use App\Services\MediaStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Collega i nostri giocatori ai profili pubblici di XFive e ne ricava
 * informazioni (nazionalità, foto, carriera) e statistiche per torneo.
 *
 * L'abbinamento è prudente: il nome deve combaciare E il profilo deve elencare
 * il nostro club E, se ci sono più candidati, l'età deve coincidere con la data
 * di nascita. Nel dubbio il giocatore resta non abbinato (e viene segnalato).
 */
final class PlayerProfileSyncer
{
    private const ROLES = ['portiere', 'difensore', 'centrocampista', 'attaccante', 'dirigente', 'allenatore'];

    public function __construct(
        private readonly XfiveClient $client,
        private readonly PlayerInfoParser $info,
        private readonly StatsTableParser $tables,
        private readonly ImageDownloader $images,
        private readonly MediaStore $media,
    ) {}

    /**
     * @return array{matched:int, skipped:int, photos:int, ambiguous:array<int,string>, not_found:array<int,string>}
     */
    public function syncProfiles(Team $own, bool $refresh = false): array
    {
        $result = ['matched' => 0, 'skipped' => 0, 'photos' => 0, 'ambiguous' => [], 'not_found' => []];
        $clubId = (int) config('amir.own.club_id');

        $players = Player::where('team_id', $own->id)->where('is_active', true)->orderBy('last_name')->get();

        foreach ($players as $player) {
            if ($player->xfive_person_id && ! $refresh) {
                $result['skipped']++;

                continue;
            }

            $match = $this->findProfile($player, $clubId);

            if ($match === 'ambiguous') {
                $result['ambiguous'][] = $player->full_name;

                continue;
            }
            if ($match === null) {
                $result['not_found'][] = $player->full_name;

                continue;
            }

            $takenByOther = Player::where('xfive_person_id', $match['id'])->where('id', '!=', $player->id)->exists();
            if ($takenByOther) {
                $result['ambiguous'][] = $player->full_name;

                continue;
            }

            $club = $match['club'];
            $update = [
                'xfive_person_id' => $match['id'],
                'nationality' => $match['page']['nationality'],
                'xfive_profile' => [
                    'age' => $match['page']['age'],
                    'role' => $club['role'],
                    'tournaments' => $club['tournaments'],
                ],
                'xfive_synced_at' => now(),
            ];

            $role = Str::lower((string) $club['role']);
            if (! $player->role && in_array($role, self::ROLES, true)) {
                $update['role'] = $role;
            }

            if ($match['avatar'] && ($refresh || ! $player->photo_path) && self::photoFollowsXfive($player)) {
                if ($this->replacePhoto($player, $match['avatar'], $update)) {
                    $result['photos']++;
                }
            }

            $player->update($update);
            $result['matched']++;
        }

        return $result;
    }

    /**
     * La foto la gestisce XFive? No se lo staff ne ha caricata una propria («upload») o l'ha tolta apposta («none»):
     * in quei casi gli aggiornamenti automatici non la toccano.
     */
    public static function photoFollowsXfive(Player $player): bool
    {
        return ! in_array($player->photo_source, ['upload', 'none'], true);
    }

    /**
     * Rilegge da XFive un giocatore, anche se i dati c'erano già: profilo (nazionalità, tornei, ruolo se manca) e foto.
     * La foto si riscarica solo se la gestisce XFive (vedi photoFollowsXfive), oppure sempre con $forcePhoto (il pulsante
     * «Aggiorna da XFive» nella scheda). Se cambia, la sagoma della figurina si butta: si rifà dalla foto nuova.
     * Segna comunque la data di lettura, così la rosa si rilegge a turno e non sempre gli stessi.
     *
     * @return array{profile: 'matched'|'not_found'|'ambiguous', photo: bool}
     */
    public function refreshPlayer(Player $player, bool $forcePhoto = false): array
    {
        $clubId = (int) config('amir.own.club_id');
        $result = ['profile' => 'not_found', 'photo' => false];
        $update = ['xfive_synced_at' => now()];

        $match = $this->findProfile($player, $clubId);
        $takenByOther = is_array($match) && Player::where('xfive_person_id', $match['id'])->where('id', '!=', $player->id)->exists();

        if ($match === 'ambiguous' || $takenByOther) {
            $result['profile'] = 'ambiguous';
        } elseif ($match !== null) {
            $result['profile'] = 'matched';
            $club = $match['club'];
            $update += [
                'xfive_person_id' => $match['id'],
                'nationality' => $match['page']['nationality'],
                'xfive_profile' => ['age' => $match['page']['age'], 'role' => $club['role'], 'tournaments' => $club['tournaments']],
            ];
            $role = Str::lower((string) $club['role']);
            if (! $player->role && in_array($role, self::ROLES, true)) {
                $update['role'] = $role;
            }
            if ($match['avatar'] && ($forcePhoto || self::photoFollowsXfive($player))) {
                $result['photo'] = $this->replacePhoto($player, $match['avatar'], $update);
            }
        }

        $player->update($update);

        return $result;
    }

    /**
     * Rilegge profili e foto di tutta la rosa attiva; con $staleOnly solo chi non viene riletto da più di una settimana
     * (è quello che fa l'aggiornamento notturno: a turno, qualche giocatore per notte). Due o tre richieste a giocatore,
     * entro il tempo dato: il resto alla prossima volta.
     *
     * @param  float|null  $deadline  istante (microtime) oltre il quale ci si ferma
     * @return array{players:int, photos:int, not_found:int, ambiguous:int, remaining:int}
     */
    public function refreshRoster(Team $own, ?float $deadline = null, bool $staleOnly = false): array
    {
        $result = ['players' => 0, 'photos' => 0, 'not_found' => 0, 'ambiguous' => 0, 'remaining' => 0];

        $players = Player::where('team_id', $own->id)->where('is_active', true)
            ->when($staleOnly, fn ($q) => $q->where(fn ($x) => $x->whereNull('xfive_synced_at')->orWhere('xfive_synced_at', '<', now()->subWeek())))
            ->get()
            ->sortBy(fn (Player $p) => [$p->xfive_synced_at?->getTimestamp() ?? 0, $p->last_name]); // prima chi aspetta da più tempo

        foreach ($players as $player) {
            if ($deadline !== null && microtime(true) > $deadline) {
                $result['remaining']++;

                continue;
            }

            $r = $this->refreshPlayer($player);
            $result['players']++;
            $result['photos'] += $r['photo'] ? 1 : 0;
            if ($r['profile'] !== 'matched') {
                $result[$r['profile']]++;
            }
        }

        return $result;
    }

    /**
     * Scarica la foto e, se è diversa da quella salvata (o non c'era), scrive percorso, provenienza e data nel $update e
     * butta la sagoma della figurina (fatta dalla foto vecchia). Dice se la foto è cambiata.
     *
     * @param  array<string, mixed>  $update
     */
    private function replacePhoto(Player $player, string $avatar, array &$update): bool
    {
        $before = $player->photo_path ? $this->media->get($player->photo_path) : null;
        $path = $this->images->store($avatar, 'player', $player->id);
        if ($path === null) {
            return false;
        }

        $after = $this->media->get($path);
        $changed = $before === null || $path !== $player->photo_path || ($after['body'] ?? null) !== $before['body'];
        if (! $changed) {
            if ($player->photo_source !== 'xfive') {
                $update['photo_source'] = 'xfive';
            }

            return false;
        }

        $update += ['photo_path' => $path, 'photo_source' => 'xfive', 'photo_updated_at' => now()];
        if ($player->cutout_path) {
            $this->media->forget($player->cutout_path);
            $update['cutout_path'] = null;
        }

        return true;
    }

    /**
     * Statistiche per torneo dalle classifiche pubbliche (marcatori, miglior
     * giocatore, disciplina). Ogni torneo a cui il giocatore ha partecipato
     * (dal suo profilo) ha una riga, anche a zero; i valori si riscrivono da zero
     * a ogni esecuzione, quindi si può rilanciare senza duplicati.
     *
     * @param  bool  $currentOnly  solo i tornei della stagione in corso (le vecchie stagioni non cambiano più)
     * @param  float|null  $deadline  istante (microtime) oltre il quale non si inizia un altro torneo: il resto alla prossima volta
     * @return array{competitions:int, rows:int, unmatched_rows:int, stopped:bool}
     */
    public function syncStats(Team $own, bool $currentOnly = false, ?float $deadline = null): array
    {
        $result = ['competitions' => 0, 'rows' => 0, 'unmatched_rows' => 0, 'stopped' => false];

        $players = Player::where('team_id', $own->id)->whereNotNull('xfive_profile')->get();
        $byKey = [];
        foreach ($players as $p) {
            $byKey[$this->key($p->last_name, $p->first_name)][] = $p;
        }

        $teamName = Str::lower($own->name);

        $competitions = Competition::where('has_own_team', true)
            ->when($currentOnly, fn ($q) => $q->where('is_current', true))
            ->orderBy('season')
            ->get();

        foreach ($competitions as $competition) {
            if ($deadline !== null && microtime(true) > $deadline) {
                $result['stopped'] = true;

                break;
            }

            foreach ($players as $p) {
                $played = collect($p->xfive_profile['tournaments'] ?? [])->pluck('id')->contains($competition->xfive_tournament_id);
                if ($played) {
                    PlayerCompetitionStat::firstOrCreate(['player_id' => $p->id, 'competition_id' => $competition->id]);
                }
            }

            PlayerCompetitionStat::where('competition_id', $competition->id)
                ->update(['goals' => 0, 'mvp_points' => 0, 'yellow' => 0, 'red' => 0]);

            foreach (['score', 'top-player', 'discipline'] as $type) {
                $table = $this->tables->parse($this->client->tournamentStats($competition->xfive_tournament_id, $type));

                foreach ($table['rows'] as $row) {
                    if (! str_contains(Str::lower((string) $row['team']), $teamName)) {
                        continue;
                    }

                    $candidates = $byKey[$this->keyFromAbbreviation($row['name'])] ?? [];
                    if (count($candidates) !== 1) {
                        $result['unmatched_rows']++;

                        continue;
                    }

                    $stat = PlayerCompetitionStat::firstOrCreate(['player_id' => $candidates[0]->id, 'competition_id' => $competition->id]);
                    $values = $row['values'];

                    match ($type) {
                        'score' => $stat->goals = $values[0] ?? 0,
                        'top-player' => $stat->mvp_points = $values[0] ?? 0,
                        default => [$stat->yellow, $stat->red] = [$values[0] ?? 0, $values[1] ?? 0],
                    };
                    $stat->save();
                    $result['rows']++;
                }
            }

            $result['competitions']++;
        }

        return $result;
    }

    /**
     * Riscarica le foto dei giocatori già abbinati a un profilo XFive ma senza foto salvata (ad esempio dopo un ripristino
     * dei dati: le immagini non stanno nei backup). Una richiesta di ricerca e una di download per giocatore; chi su XFive
     * non ha una foto vera non si riprova per una settimana.
     *
     * @param  float|null  $deadline  istante (microtime) oltre il quale ci si ferma: il resto alla prossima volta
     * @return array{photos:int, remaining:int}
     */
    public function fillMissingPhotos(Team $own, ?float $deadline = null): array
    {
        $result = ['photos' => 0, 'remaining' => 0];

        // chi ha un indirizzo della foto (gli ex giocatori) si scarica direttamente; gli altri si cercano per nome fra i profili.
        // «Senza foto» vuol dire che l'immagine non c'è davvero, non solo che manca il percorso: dopo un ripristino i percorsi tornano, le immagini no.
        $candidates = Player::where('team_id', $own->id)
            ->where(fn ($q) => $q->whereNotNull('photo_url')->orWhere(fn ($x) => $x->where('is_active', true)->whereNotNull('xfive_person_id')))
            ->orderByDesc('is_active')
            ->orderBy('last_name')
            ->orderBy('id')
            ->get();
        $stored = $this->media->existingPaths($candidates->pluck('photo_path')->filter()->all());

        $players = $candidates
            ->reject(fn (Player $p) => $p->photo_path && isset($stored[$p->photo_path]))
            ->reject(fn (Player $p) => $p->photo_source === 'none') // tolta dallo staff: non torna da sola
            ->reject(fn (Player $p) => Cache::has("amir:photo-missing:{$p->id}"));

        foreach ($players as $player) {
            if ($deadline !== null && microtime(true) > $deadline) {
                $result['remaining']++;

                continue;
            }

            $avatar = $this->remoteImageUrl($player->photo_url);
            if ($avatar === null && $player->is_active && $player->xfive_person_id) {
                foreach ($this->client->finder(trim($player->first_name.' '.$player->last_name)) as $item) {
                    if (preg_match('#/player-info/(\d+)/#', (string) ($item['url'] ?? ''), $m) && (int) $m[1] === (int) $player->xfive_person_id) {
                        $avatar = $this->avatarUrl((string) ($item['avatar'] ?? ''));

                        break;
                    }
                }
            }

            $path = $avatar ? $this->images->store($avatar, 'player', $player->id) : null;
            if ($path === null) {
                Cache::put("amir:photo-missing:{$player->id}", true, now()->addWeek());

                continue;
            }

            $player->update(['photo_path' => $path, 'photo_source' => $player->photo_source === 'upload' ? 'upload' : 'xfive', 'photo_updated_at' => now()]);
            $result['photos']++;
        }

        return $result;
    }

    /** @return array<string,mixed>|'ambiguous'|null */
    private function findProfile(Player $player, int $clubId): array|string|null
    {
        $term = trim($player->first_name.' '.$player->last_name);
        $wanted = $this->tokens($term);

        $candidates = [];
        foreach ($this->client->finder($term) as $item) {
            if (! preg_match('#/player-info/(\d+)/[^/]+/#', (string) ($item['url'] ?? ''), $m)) {
                continue;
            }

            $label = $this->tokens((string) ($item['labelt'] ?? ''));
            if (! ($this->subset($label, $wanted) || $this->subset($wanted, $label))) {
                continue;
            }

            $candidates[] = [
                'id' => (int) $m[1],
                'path' => (string) parse_url($item['url'], PHP_URL_PATH),
                'avatar' => $this->avatarUrl((string) ($item['avatar'] ?? '')),
            ];
        }

        $all = [];
        foreach ($candidates as $candidate) {
            $page = $this->info->parse($this->client->get($candidate['path']));
            $all[] = $candidate + ['page' => $page, 'club' => collect($page['clubs'])->firstWhere('club_id', $clubId)];
        }

        if ($all === []) {
            return null;
        }

        // 1) il profilo elenca il nostro club: se è uno solo, è lui
        $verified = array_values(array_filter($all, fn (array $v) => $v['club'] !== null));
        if (count($verified) === 1) {
            return $verified[0];
        }

        // 2) più profili con il nostro club, oppure nessuno (tesserato nuovo, ancora senza storia con noi):
        //    si accetta solo chi ha anche l'età giusta, e deve essere uno solo
        $pool = $verified !== [] ? $verified : $all;
        $age = $player->birth_date?->age;
        $sameAge = array_values(array_filter($pool, fn (array $v) => $age !== null && $v['page']['age'] === $age));

        if (count($sameAge) === 1) {
            $match = $sameAge[0];
            $match['club'] ??= ['club_id' => $clubId, 'name' => '', 'role' => null, 'tournaments' => []];

            return $match;
        }

        return ($verified === [] && $sameAge === []) ? null : 'ambiguous';
    }

    private function avatarUrl(string $html): ?string
    {
        return preg_match('/src="([^"]+)"/', $html, $m) ? $m[1] : null;
    }

    /** L'indirizzo di una foto già nota, solo se è del CDN di XFive (le foto proprie dei giocatori non si scaricano da altrove). */
    private function remoteImageUrl(?string $url): ?string
    {
        return $url !== null && in_array(parse_url($url, PHP_URL_HOST), ['cdn.enjore.com', 'www.xfivesport.it'], true) ? $url : null;
    }

    /** @return array<int,string> */
    private function tokens(string $text): array
    {
        $clean = Str::of($text)->ascii()->lower()->replaceMatches('/[^a-z0-9 ]+/', ' ')->squish()->toString();

        return $clean === '' ? [] : explode(' ', $clean);
    }

    /** @param array<int,string> $small @param array<int,string> $big */
    private function subset(array $small, array $big): bool
    {
        return $small !== [] && array_diff($small, $big) === [];
    }

    /** "Rossi" + "Mario" -> "rossi|m" */
    private function key(string $last, string $first): string
    {
        return Str::of($last)->ascii()->lower()->squish().'|'.Str::of($first)->ascii()->lower()->substr(0, 1);
    }

    /** "De Simone L." -> "de simone|l" */
    private function keyFromAbbreviation(string $label): string
    {
        if (preg_match('/^(.*\S)\s+([\p{L}])\.?$/u', trim($label), $m)) {
            return $this->key($m[1], $m[2]);
        }

        return $this->key($label, '');
    }
}
