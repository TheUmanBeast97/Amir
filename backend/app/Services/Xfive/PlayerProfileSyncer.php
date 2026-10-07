<?php

namespace App\Services\Xfive;

use App\Models\Competition;
use App\Models\Player;
use App\Models\PlayerCompetitionStat;
use App\Models\Team;
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

            if ($match['avatar'] && ($refresh || ! $player->photo_path)) {
                if ($path = $this->images->store($match['avatar'], 'player', $player->id)) {
                    $update['photo_path'] = $path;
                    $result['photos']++;
                }
            }

            $player->update($update);
            $result['matched']++;
        }

        return $result;
    }

    /**
     * Statistiche per torneo dalle classifiche pubbliche (marcatori, miglior
     * giocatore, disciplina). Ogni torneo a cui il giocatore ha partecipato
     * (dal suo profilo) ha una riga, anche a zero; i valori si riscrivono da zero
     * a ogni esecuzione, quindi si può rilanciare senza duplicati.
     *
     * @return array{competitions:int, rows:int, unmatched_rows:int}
     */
    public function syncStats(Team $own): array
    {
        $result = ['competitions' => 0, 'rows' => 0, 'unmatched_rows' => 0];

        $players = Player::where('team_id', $own->id)->whereNotNull('xfive_profile')->get();
        $byKey = [];
        foreach ($players as $p) {
            $byKey[$this->key($p->last_name, $p->first_name)][] = $p;
        }

        $teamName = Str::lower($own->name);

        foreach (Competition::where('has_own_team', true)->orderBy('season')->get() as $competition) {
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
