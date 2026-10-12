<?php

namespace App\Services\Zone;

use App\Models\Zone\XfClub;
use App\Models\Zone\XfDocument;
use App\Models\Zone\XfMatch;
use App\Models\Zone\XfMatchPlayer;
use App\Models\Zone\XfPlayer;
use App\Models\Zone\XfPlayerStat;
use App\Models\Zone\XfSeason;
use App\Models\Zone\XfStanding;
use App\Models\Zone\XfSyncState;
use App\Models\Zone\XfTeam;
use App\Models\Zone\XfTeamPlayer;
use App\Models\Zone\XfTournament;
use App\Support\Kickoff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Porta i dati pubblici di XFive (i JSON dell'archivio locale, o gli stessi dati letti dal vivo) nelle tabelle xf_*.
 * Ogni metodo è idempotente (upsert sulle chiavi uniche: rilanciare non duplica) e restituisce dei conteggi
 * (chiavi distinte per non confondersi quando si sommano: tournaments, clubs, linked, teams, roster, players, matches,
 * standings, groups, stats, documents, reports, lineup, skipped).
 * Ordine giusto su un database vuoto: tornei, squadre, club, profili, calendari, tabelle, referti (quello di ZoneExporter):
 * le squadre prima dei club, perché è la pagina del club ad abbinare le rose ai profili globali.
 * Solo calcio: i tornei di altri sport non si scrivono.
 */
final class ZoneImporter
{
    /** Vero se lo sport è un calcio («Calcio a 8 - Maschile», «Calcio a 7 Over - Maschile»...). */
    public static function isFootball(string $sport): bool
    {
        return str_starts_with(mb_strtolower(trim($sport)), 'calcio');
    }

    /** Testo per i confronti: ascii minuscolo, senza accenti né spazi doppi («CAFFÈ  KM0» → «caffe km0»). */
    public static function normalize(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower(Str::ascii($s))));
    }

    /** Ruoli che XFive attacca in coda al nome nel profilo («Zunino Mattia Centrocampista 7»). */
    private const NAME_TAILS = ['portiere', 'difensore', 'centrocampista', 'attaccante', 'universale', 'pivot', 'laterale', 'esterno', 'terzino', 'mediano', 'trequartista', 'punta', 'ala', 'allenatore', 'dirigente', 'giocatore'];

    /** Il nome del profilo senza numero di maglia né ruolo in coda: «Verdi Luca 9» e «Zunino Mattia Centrocampista 7» diventano «Verdi Luca» e «Zunino Mattia». */
    public static function cleanPlayerName(string $raw): string
    {
        $words = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        while (count($words) > 1) {
            $last = mb_strtolower(Str::ascii((string) end($words)));
            if (! preg_match('/^\d+$/', $last) && ! in_array($last, self::NAME_TAILS, true)) {
                break;
            }
            array_pop($words);
        }

        return implode(' ', $words);
    }

    /** Id delle persone ricavate dai referti: partono da qui, ben sopra i pid di XFive (oggi sotto i 10.000). */
    public const DERIVED_BASE = 1_000_000;

    /** Gli slug che non sono persone: «00-00» e simili, il segnaposto che XFive mette nelle distinte lasciate vuote. */
    public static function isPlaceholderSlug(?string $slug): bool
    {
        return $slug === null || trim($slug) === '' || ! preg_match('/\p{L}/u', $slug);
    }

    /**
     * Collega ogni riga di distinta e di rosa alla persona giusta, partendo dal nome: lo slug di XFive è un'identità
     * affidabile (su 74.000 righe di distinta c'è un solo caso di omonimi nella stessa partita). Prima si crea, per chi non
     * ha ancora una persona, quella ricavata dai referti (XFive dà il profilo solo a chi è nella rosa attuale di un club);
     * poi ogni riga con lo slug di una sola persona la prende, in qualunque squadra e torneo giochi: è così che un giocatore
     * ha tutte le sue partite e non solo quelle della squadra in cui XFive lo elenca.
     * Idempotente. Senza argomenti guarda tutto; con $matchIds / $teamIds (anche vuoti) solo le righe di quelle partite e squadre.
     *
     * @param  array<int,int>|null  $matchIds
     * @param  array<int,int>|null  $teamIds
     * @return array{created:int, match_rows:int, roster_rows:int}
     */
    public function linkPlayers(?array $matchIds = null, ?array $teamIds = null): array
    {
        $created = $this->createDerived($matchIds, $teamIds);

        return [
            'created' => $created,
            'match_rows' => $this->linkRows('xf_match_players', 'match_id', $matchIds),
            'roster_rows' => $this->linkRows('xf_team_players', 'team_id', $teamIds),
        ];
    }

    /** Crea le persone ricavate dai referti per gli slug senza persona (segnaposto esclusi). Restituisce quante. */
    private function createDerived(?array $matchIds, ?array $teamIds): int
    {
        $found = []; // slug => [nome, foto]
        foreach ([['xf_match_players', 'match_id', $matchIds], ['xf_team_players', 'team_id', $teamIds]] as [$table, $column, $ids]) {
            if ($ids !== null && $ids === []) {
                continue;
            }
            $rows = DB::table($table)->whereNull('player_id')->whereNotNull('slug')->where('slug', '!=', '')
                ->when($ids !== null, fn ($q) => $q->whereIn($column, $ids))
                ->groupBy('slug')->select('slug', DB::raw('MAX(name) as name'), DB::raw('MAX(photo_url) as photo_url'))->get();
            foreach ($rows as $r) {
                if (! self::isPlaceholderSlug($r->slug)) {
                    $known = $found[$r->slug] ?? [null, null];
                    $found[$r->slug] = [$known[0] ?? $r->name, $known[1] ?? $r->photo_url];
                }
            }
        }
        if (! $found) {
            return 0;
        }

        $existing = [];
        foreach (array_chunk(array_keys($found), 500) as $chunk) {
            foreach (XfPlayer::query()->whereIn('slug', $chunk)->pluck('slug') as $slug) {
                $existing[$slug] = true;
            }
        }

        $next = max(self::DERIVED_BASE, (int) XfPlayer::query()->max('id')) + 1;
        $now = now();
        $new = [];
        foreach ($found as $slug => [$name, $photo]) {
            if (isset($existing[$slug])) {
                continue;
            }
            $clean = self::cleanPlayerName((string) $name);
            $new[] = ['id' => $next++, 'name' => $clean, 'search' => self::normalize($clean), 'slug' => (string) $slug, 'photo_url' => $photo, 'derived' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($new, 200) as $chunk) {
            XfPlayer::insert($chunk);
        }

        return count($new);
    }

    /**
     * Dà a ogni riga ancora senza persona quella con il suo slug, se è una sola (in una sola istruzione).
     *
     * @param  array<int,int>|null  $ids  solo le righe di queste partite/squadre (null = tutte, [] = nessuna)
     */
    private function linkRows(string $table, string $scopeColumn, ?array $ids): int
    {
        if ($ids !== null && $ids === []) {
            return 0;
        }
        $scope = $ids === null ? '' : " AND {$scopeColumn} IN (".implode(',', array_map('intval', $ids)).')';

        return DB::update(
            "UPDATE {$table} SET player_id = (SELECT p.id FROM xf_players p WHERE p.slug = {$table}.slug) "
            ."WHERE player_id IS NULL AND slug IS NOT NULL AND slug <> '' "
            ."AND (SELECT COUNT(*) FROM xf_players p WHERE p.slug = {$table}.slug) = 1{$scope}"
        );
    }

    /** Arriva il profilo vero di XFive di uno slug che aveva già una persona ricavata dai referti: la assorbe e la toglie. */
    private function adoptDerived(int $pid, string $slug): void
    {
        $derived = XfPlayer::query()->where('slug', $slug)->where('derived', true)->where('id', '!=', $pid)->pluck('id')->all();
        if (! $derived) {
            return;
        }
        DB::table('xf_match_players')->whereIn('player_id', $derived)->update(['player_id' => $pid]);
        DB::table('xf_team_players')->whereIn('player_id', $derived)->update(['player_id' => $pid]);
        XfPlayer::query()->whereIn('id', $derived)->delete();
    }

    /** L'intestazione di un torneo: null (e nessuna scrittura) se non è calcio o non esiste. */
    public function tournament(array $header): ?XfTournament
    {
        $sport = (string) ($header['sport'] ?? '');
        if (($header['exists'] ?? true) === false || ! self::isFootball($sport) || empty($header['id']) || empty($header['season_id'])) {
            return null;
        }

        $seasonLabel = (string) ($header['season'] ?? '');
        XfSeason::updateOrCreate(['id' => (int) $header['season_id']], ['label' => mb_substr($seasonLabel, 0, 9)]);

        $format = preg_match('/calcio\s+a\s+(\d{1,2})/i', $sport, $m) ? (int) $m[1] : null;
        $gender = str_contains($sport, ' - ') ? trim(mb_substr($sport, mb_strrpos($sport, ' - ') + 3)) : null;
        $status = in_array($header['status'] ?? null, ['ongoing', 'incoming', 'previous'], true) ? $header['status'] : 'previous';

        return XfTournament::updateOrCreate(['id' => (int) $header['id']], [
            'season_id' => (int) $header['season_id'],
            'name' => (string) ($header['name'] ?? ''),
            'search' => self::normalize((string) ($header['name'] ?? '')),
            'slug' => (string) ($header['slug'] ?? ''),
            'sport' => $sport,
            'format' => $format,
            'gender' => $gender !== null ? mb_substr($gender, 0, 20) : null,
            'category' => $header['category'] ?? null,
            'flyer_url' => $header['flyer_url'] ?? null,
            'teams_count' => isset($header['teams']) ? (int) $header['teams'] : null,
            'dates' => $header['dates'] ?? null,
            'status' => $status,
        ]);
    }

    /**
     * Il calendario di un torneo (righe di calendar.json): partite in xf_matches e club mancanti in xf_clubs.
     *
     * @return array<string,int>
     */
    public function calendar(int $tid, array $rows, string $seasonLabel): array
    {
        $now = now();
        $matches = [];
        $clubs = [];
        // le coppe a tabellone non hanno gli id dei club nel calendario: il club si ricava dal nome della squadra nel torneo
        $byName = null;
        $clubOf = function (array $side) use (&$byName, $tid): int {
            $cid = (int) ($side['club_id'] ?? 0);
            if ($cid > 0) {
                return $cid;
            }
            $name = self::normalize((string) ($side['name'] ?? ''));
            if ($name === '') {
                return 0;
            }
            if ($byName === null) {
                $byName = [];
                foreach (XfTeam::query()->where('tournament_id', $tid)->whereNotNull('club_id')->get(['name', 'club_id']) as $t) {
                    $byName[self::normalize($t->name)] = (int) $t->club_id;
                }
                $byName += $this->clubsByName();
            }

            return $byName[$name] ?? 0;
        };
        foreach ($rows as $r) {
            $mid = (int) ($r['xfive_match_id'] ?? 0);
            if ($mid <= 0) {
                continue;
            }
            $homeClub = $clubOf((array) ($r['home'] ?? []));
            $awayClub = $clubOf((array) ($r['away'] ?? []));
            foreach (['home', 'away'] as $side) {
                $cid = (int) ($r[$side]['club_id'] ?? 0);
                if ($cid > 0 && ! isset($clubs[$cid])) {
                    $name = (string) ($r[$side]['name'] ?? '');
                    $clubs[$cid] = ['id' => $cid, 'name' => $name, 'search' => self::normalize($name), 'slug' => null, 'badge_url' => $r[$side]['badge_url'] ?? null, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            $home = $r['home_score'] ?? null;
            $away = $r['away_score'] ?? null;
            $matches[$mid] = [
                'id' => $mid,
                'tournament_id' => $tid,
                'round' => isset($r['round']) ? (int) $r['round'] : null,
                'round_label' => $r['round_label'] ?? null,
                'round_id' => isset($r['round_id']) ? (int) $r['round_id'] : null,
                'home_club_id' => $homeClub ?: null,
                'away_club_id' => $awayClub ?: null,
                'home_name' => (string) ($r['home']['name'] ?? ''),
                'away_name' => (string) ($r['away']['name'] ?? ''),
                'kickoff_at' => Kickoff::parse($r['kickoff_raw'] ?? null, $seasonLabel),
                'kickoff_raw' => $r['kickoff_raw'] ?? null,
                'venue' => $r['venue'] ?? null,
                'home_score' => $home !== null ? (int) $home : null,
                'away_score' => $away !== null ? (int) $away : null,
                'played' => $home !== null && $away !== null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($clubs, 200) as $chunk) {
            XfClub::insertOrIgnore($chunk);
        }
        foreach (array_chunk(array_values($matches), 200) as $chunk) {
            XfMatch::upsert($chunk, ['id'], ['tournament_id', 'round', 'round_label', 'round_id', 'home_club_id', 'away_club_id', 'home_name', 'away_name', 'kickoff_at', 'kickoff_raw', 'venue', 'home_score', 'away_score', 'played', 'updated_at']);
        }

        return ['matches' => count($matches), 'clubs' => count($clubs)];
    }

    /**
     * La classifica di un torneo (gruppi di standings.json): le righe del torneo si cancellano e si riscrivono;
     * il club si abbina per nome normalizzato. `group` si salva come «» quando non ci sono gironi.
     *
     * @return array<string,int>
     */
    public function standings(int $tid, array $groups): array
    {
        $byName = $this->clubsByName();
        $rows = [];
        foreach ($groups as $g) {
            $group = (string) ($g['group'] ?? '');
            foreach ((array) ($g['rows'] ?? []) as $r) {
                $name = (string) ($r['name'] ?? '');
                $rows[$group.'|'.(int) ($r['position'] ?? 0)] = [
                    'tournament_id' => $tid,
                    'group' => $group,
                    'position' => (int) ($r['position'] ?? 0),
                    'club_id' => $byName[self::normalize($name)] ?? null,
                    'name' => $name,
                    'badge_url' => $r['badge_url'] ?? null,
                    'values' => json_encode((array) ($r['values'] ?? []), JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        XfStanding::where('tournament_id', $tid)->delete();
        foreach (array_chunk(array_values($rows), 200) as $chunk) {
            XfStanding::insert($chunk);
        }

        return ['standings' => count($rows), 'groups' => count($groups)];
    }

    /**
     * Le statistiche giocatori di un torneo come le pubblica XFive (player-stats.json: score, top-player, discipline).
     *
     * @return array<string,int>
     */
    public function playerStats(int $tid, array $tables): array
    {
        $rows = [];
        foreach ($tables as $type => $table) {
            $type = (string) $type;
            foreach ((array) ($table['rows'] ?? []) as $r) {
                $rows[$type.'|'.(int) ($r['position'] ?? 0)] = [
                    'tournament_id' => $tid,
                    'type' => mb_substr($type, 0, 12),
                    'position' => (int) ($r['position'] ?? 0),
                    'name' => (string) ($r['name'] ?? ''),
                    'team' => $r['team'] ?? null,
                    'photo_url' => $r['photo'] ?? $r['photo_url'] ?? null,
                    'values' => json_encode(array_values((array) ($r['values'] ?? [])), JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        XfPlayerStat::where('tournament_id', $tid)->delete();
        foreach (array_chunk(array_values($rows), 200) as $chunk) {
            XfPlayerStat::insert($chunk);
        }

        return ['stats' => count($rows)];
    }

    /**
     * La modulistica di un torneo (docs.json).
     *
     * @return array<string,int>
     */
    public function documents(int $tid, array $docs): array
    {
        $rows = [];
        foreach ($docs as $d) {
            $url = (string) ($d['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $rows[$url] = ['tournament_id' => $tid, 'url' => mb_substr($url, 0, 500), 'title' => $d['title'] ?? null];
        }
        foreach (array_chunk(array_values($rows), 200) as $chunk) {
            XfDocument::upsert($chunk, ['tournament_id', 'url'], ['title']);
        }

        return ['documents' => count($rows)];
    }

    /**
     * Una squadra in un torneo con la sua rosa (teams/{teamId}.json). Il profilo globale (player_id) si abbina
     * se un'altra riga dello stesso club con lo stesso slug lo conosce già.
     *
     * @return array<string,int>
     */
    public function team(array $team): array
    {
        $teamId = (int) ($team['id'] ?? 0);
        $tid = (int) ($team['tournament_id'] ?? 0);
        if ($teamId <= 0 || $tid <= 0) {
            return ['teams' => 0, 'roster' => 0];
        }
        $clubId = ((int) ($team['club_id'] ?? 0)) ?: null;
        $now = now();

        XfTeam::upsert([[
            'id' => $teamId,
            'tournament_id' => $tid,
            'club_id' => $clubId,
            'name' => (string) ($team['name'] ?? ''),
            'slug' => $team['slug'] ?? null,
            'badge_url' => $team['badge_url'] ?? null,
            'staff' => ! empty($team['staff']) ? json_encode($team['staff'], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['id'], ['tournament_id', 'club_id', 'name', 'slug', 'badge_url', 'staff', 'updated_at']);

        $rows = [];
        foreach ((array) ($team['players'] ?? []) as $p) {
            $tpid = (int) ($p['id'] ?? 0);
            if ($tpid <= 0) {
                continue;
            }
            $rows[$tpid] = [
                'team_id' => $teamId,
                'tpid' => $tpid,
                'player_id' => null,
                'name' => (string) ($p['name'] ?? ''),
                'slug' => $p['slug'] ?? null,
                'role' => $p['role'] ?? null,
                'number' => isset($p['number']) && $p['number'] !== '' ? mb_substr((string) $p['number'], 0, 4) : null,
                'photo_url' => $p['photo_url'] ?? null,
                'country' => $p['country'] ?? null,
            ];
        }

        if ($clubId && $rows) {
            $slugs = array_values(array_filter(array_unique(array_column($rows, 'slug'))));
            $known = XfTeamPlayer::query()
                ->join('xf_teams', 'xf_teams.id', '=', 'xf_team_players.team_id')
                ->where('xf_teams.club_id', $clubId)
                ->whereNotNull('xf_team_players.player_id')
                ->whereIn('xf_team_players.slug', $slugs)
                ->pluck('xf_team_players.player_id', 'xf_team_players.slug');
            foreach ($rows as &$row) {
                if ($row['slug'] !== null && isset($known[$row['slug']])) {
                    $row['player_id'] = (int) $known[$row['slug']];
                }
            }
            unset($row);
        }

        foreach (array_chunk(array_values($rows), 200) as $chunk) {
            XfTeamPlayer::upsert($chunk, ['team_id', 'tpid'], ['player_id', 'name', 'slug', 'role', 'number', 'photo_url', 'country']);
        }

        return ['teams' => 1, 'roster' => count($rows)];
    }

    /**
     * Un club (clubs/{clubId}.json): il club stesso e, per ogni suo giocatore {id, slug}, il profilo globale nelle rose
     * delle squadre di quel club con lo stesso slug.
     *
     * @return array<string,int>
     */
    public function club(array $club): array
    {
        $clubId = (int) ($club['id'] ?? 0);
        if ($clubId <= 0) {
            return ['clubs' => 0, 'linked' => 0];
        }
        $now = now();
        XfClub::upsert([[
            'id' => $clubId,
            'name' => (string) ($club['name'] ?? ''),
            'search' => self::normalize((string) ($club['name'] ?? '')),
            'slug' => $club['slug'] ?? null,
            'badge_url' => $club['badge_url'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['id'], ['name', 'search', 'slug', 'badge_url', 'updated_at']);

        $linked = 0;
        $teamIds = XfTeam::where('club_id', $clubId)->pluck('id')->all();
        if ($teamIds) {
            $bySlug = [];
            foreach ((array) ($club['players'] ?? []) as $p) {
                $pid = (int) ($p['id'] ?? 0);
                $slug = (string) ($p['slug'] ?? '');
                if ($pid > 0 && $slug !== '') {
                    $bySlug[$slug] = $pid;
                }
            }
            foreach ($bySlug as $slug => $pid) {
                $linked += XfTeamPlayer::whereIn('team_id', $teamIds)->where('slug', $slug)
                    ->where(fn ($q) => $q->whereNull('player_id')->orWhere('player_id', '!=', $pid))
                    ->update(['player_id' => $pid]);
            }
        }

        return ['clubs' => 1, 'linked' => $linked];
    }

    /**
     * Il profilo globale di un giocatore (players/{pid}.json). Il nome perde il numero di maglia finale.
     *
     * @return array<string,int>
     */
    public function player(array $profile): array
    {
        $pid = (int) ($profile['id'] ?? 0);
        if ($pid <= 0) {
            return ['players' => 0];
        }
        $name = self::cleanPlayerName((string) ($profile['name'] ?? ''));
        $age = isset($profile['age']) && is_numeric($profile['age']) ? (int) $profile['age'] : null;

        $slug = (string) ($profile['slug'] ?? '');
        XfPlayer::updateOrCreate(['id' => $pid], [
            'name' => $name,
            'search' => self::normalize($name),
            'slug' => $slug !== '' ? $slug : null,
            'photo_url' => $profile['photo_url'] ?? null,
            'age' => $age !== null && $age >= 0 && $age < 120 ? $age : null,
            'nationality' => $profile['nationality'] ?? null,
            'profile' => $profile['clubs'] ?? null,
            'derived' => false,
            'synced_at' => now(),
        ]);
        if ($slug !== '') {
            $this->adoptDerived($pid, $slug);
        }

        return ['players' => 1];
    }

    /**
     * Il referto di una partita (matches/{mid}.json): le due distinte in xf_match_players, arbitro, punteggi e
     * has_report sulla partita. I giocatori si abbinano al profilo attraverso le rose delle squadre del torneo
     * (stesso tpid); chi non è in nessuna rosa resta nel referto con player_id null.
     * Se la partita non è in calendario (o il torneo non è di calcio) non si scrive nulla.
     *
     * @return array<string,int>
     */
    public function report(array $match, bool $link = true): array
    {
        $mid = (int) ($match['id'] ?? 0);
        $m = $mid > 0 ? XfMatch::find($mid) : null;
        if (! $m) {
            return ['reports' => 0, 'lineup' => 0, 'skipped' => 1];
        }

        $rows = [];
        foreach (['home', 'away'] as $side) {
            foreach ((array) ($match[$side]['lineup'] ?? []) as $p) {
                $tpid = (int) ($p['ref'] ?? 0);
                if ($tpid <= 0 || isset($rows[$tpid])) {
                    continue;
                }
                $rows[$tpid] = [
                    'match_id' => $mid,
                    'side' => $side,
                    'tpid' => $tpid,
                    'player_id' => null,
                    'name' => (string) ($p['name'] ?? ''),
                    'slug' => $p['slug'] ?? null,
                    'goals' => (int) ($p['goals'] ?? 0),
                    'yellow' => (int) ($p['yellow'] ?? 0),
                    'red' => (int) ($p['red'] ?? 0),
                    'mvp' => (bool) ($p['mvp'] ?? false),
                    'photo_url' => $p['photo'] ?? $p['photo_url'] ?? null,
                ];
            }
        }

        if ($rows) {
            $teamIds = XfTeam::where('tournament_id', $m->tournament_id)->pluck('id')->all();
            if ($teamIds) {
                $known = XfTeamPlayer::whereIn('team_id', $teamIds)->whereIn('tpid', array_keys($rows))->whereNotNull('player_id')->pluck('player_id', 'tpid');
                foreach ($known as $tpid => $pid) {
                    $rows[(int) $tpid]['player_id'] = (int) $pid;
                }
            }
            foreach (array_chunk(array_values($rows), 200) as $chunk) {
                XfMatchPlayer::upsert($chunk, ['match_id', 'tpid'], ['side', 'player_id', 'name', 'slug', 'goals', 'yellow', 'red', 'mvp', 'photo_url']);
            }
        }

        $home = $match['home_score'] ?? $m->home_score;
        $away = $match['away_score'] ?? $m->away_score;
        $m->forceFill([
            'referee' => $match['referee'] ?? $m->referee,
            'venue' => $match['venue'] ?? $m->venue,
            'home_score' => $home !== null ? (int) $home : null,
            'away_score' => $away !== null ? (int) $away : null,
            'played' => $home !== null && $away !== null,
            'has_report' => true,
        ])->save();

        if ($link) {
            $this->linkPlayers([$mid], []); // chi non è in rosa si abbina per nome, o diventa una persona ricavata dal referto
        }

        return ['reports' => 1, 'lineup' => count($rows)];
    }

    // ------------------------------------------------------------------ pezzi dell'export (ZoneExporter)

    /**
     * Un pezzo dell'export (un file .json.gz: {"section", "items"}): lo decomprime, importa la sezione in una sola
     * transazione e aggiorna xf_sync_state della sezione (synced_at e i conteggi di questo pezzo).
     *
     * @return array{section: string, items: int, counts: array<string,int>}
     *
     * @throws InvalidArgumentException se il contenuto non è un pezzo dell'export
     */
    public function chunk(string $gzip): array
    {
        $json = @gzdecode($gzip);
        $data = $json !== false ? json_decode($json, true) : null;
        $section = is_array($data) ? (string) ($data['section'] ?? '') : '';
        if (! in_array($section, ZoneExporter::SECTIONS, true) || ! is_array($data['items'] ?? null)) {
            throw new InvalidArgumentException('Il file non è un pezzo dell\'export della Mixed Zone (zone-NNN-sezione.json.gz).');
        }
        $items = array_values($data['items']);

        $counts = DB::transaction(fn () => $this->section($section, $items));
        XfSyncState::updateOrCreate(['section' => $section], ['synced_at' => now(), 'counts' => ['items' => count($items)] + $counts]);

        return ['section' => $section, 'items' => count($items), 'counts' => $counts];
    }

    /**
     * Gli elementi di una sezione dell'export, ognuno al metodo giusto; i conteggi si sommano.
     * Calendari e tabelle di tornei non importati (non di calcio) si saltano; le date *_synced_at del torneo si segnano.
     *
     * @param  array<int, array<mixed>>  $items
     * @return array<string,int>
     */
    public function section(string $section, array $items): array
    {
        $totals = [];
        $add = function (array $counts) use (&$totals): void {
            foreach ($counts as $k => $v) {
                $totals[$k] = ($totals[$k] ?? 0) + (int) $v;
            }
        };
        $now = now();
        $matchIds = [];
        $teamIds = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            switch ($section) {
                case 'tournaments':
                    $add($this->tournament($item) ? ['tournaments' => 1] : ['skipped' => 1]);
                    break;
                case 'clubs':
                    $add($this->club($item));
                    break;
                case 'teams':
                    $add($this->team($item));
                    $teamIds[] = (int) ($item['id'] ?? 0);
                    if (! empty($item['tournament_id'])) {
                        XfTournament::whereKey((int) $item['tournament_id'])->update(['teams_synced_at' => $now]);
                    }
                    break;
                case 'players':
                    $add($this->player($item));
                    break;
                case 'calendar':
                    $tid = (int) ($item['tournament_id'] ?? 0);
                    if (! XfTournament::whereKey($tid)->exists()) {
                        $add(['skipped' => 1]);
                        break;
                    }
                    $add($this->calendar($tid, (array) ($item['rows'] ?? []), (string) ($item['season'] ?? '')));
                    XfTournament::whereKey($tid)->update(['calendar_synced_at' => $now]);
                    break;
                case 'tables':
                    $tid = (int) ($item['tournament_id'] ?? 0);
                    if (! XfTournament::whereKey($tid)->exists()) {
                        $add(['skipped' => 1]);
                        break;
                    }
                    $add($this->standings($tid, (array) ($item['standings'] ?? [])));
                    $add($this->playerStats($tid, (array) ($item['player_stats'] ?? [])));
                    $add($this->documents($tid, (array) ($item['docs'] ?? [])));
                    XfTournament::whereKey($tid)->update(['standings_synced_at' => $now, 'stats_synced_at' => $now]);
                    break;
                case 'reports':
                    $add($this->report($item, false));
                    $matchIds[] = (int) ($item['id'] ?? 0);
                    break;
                default:
                    throw new InvalidArgumentException("Sezione sconosciuta: {$section}.");
            }
        }

        // rose e distinte si collegano alle persone una volta per pezzo (profilo vero o persona ricavata dai referti)
        if ($matchIds || $teamIds) {
            $add(['persons' => $this->linkPlayers($matchIds, $teamIds)['created']]);
        }

        return $totals;
    }

    /** @return array<string,int> nome normalizzato => id del club */
    private function clubsByName(): array
    {
        $map = [];
        foreach (XfClub::query()->get(['id', 'name']) as $c) {
            $map[self::normalize($c->name)] = (int) $c->id;
        }

        return $map;
    }
}
