<?php

namespace App\Services\Xfive\Archive;

use App\Services\Xfive\MatchPageParser;
use App\Services\Xfive\PlayerInfoParser;
use App\Services\Xfive\PrintableCalendarParser;
use App\Services\Xfive\StatsTableParser;
use App\Services\Xfive\XfiveClient;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Lo scarico completo dei dati pubblici di XFive nell'archivio locale, a tappe che riprendono da dove si erano fermate
 * (quello che è già in json/ non si richiede più, salvo --refresh):
 *
 *   tournaments  tutti i tornei: dall'elenco per stagione (league.php op=23) e, per le stagioni vecchie che l'elenco non
 *                dà più, provando gli id uno a uno (--scan=da-a); di ogni torneo nome, stagione, sport, locandina
 *   details      per ogni torneo: calendario e risultati, classifica, statistiche giocatori, squadre, modulistica
 *   teams        per ogni squadra di ogni torneo: rosa (nomi, numeri, ruoli, foto), dirigenti, club di appartenenza
 *   clubs        per ogni club: la pagina storica con i profili globali dei giocatori
 *   players      per ogni profilo globale: età, nazionalità, carriera (club e tornei)
 *   matches      per ogni partita giocata: arbitro, distinte, marcatori, cartellini
 *   images       locandine, stemmi e foto in img/
 *   markdown     una pagina leggibile per torneo in md/
 *
 * Ogni richiesta passa dal freno (Limiter). I numeri di ogni tappa finiscono nel manifest.
 */
final class XfiveArchiver
{
    public const STAGES = ['tournaments', 'details', 'teams', 'clubs', 'players', 'matches', 'images', 'markdown'];

    private int $budget = PHP_INT_MAX;

    /** @var Closure(string):void */
    private Closure $say;

    public function __construct(
        private readonly XfiveClient $client,
        private readonly Limiter $limiter,
        private readonly Archive $archive,
        private readonly PrintableCalendarParser $calendar,
        private readonly StatsTableParser $tables,
        private readonly MatchPageParser $matchPage,
        private readonly PlayerInfoParser $playerInfo,
        private readonly bool $refresh = false,
    ) {
        $this->say = fn (string $line) => null;
    }

    /** @param  Closure(string):void  $say */
    public function reportTo(Closure $say): void
    {
        $this->say = $say;
    }

    /** Quante richieste al massimo in questa esecuzione: finito il budget la tappa si ferma e riprende la volta dopo. */
    public function withBudget(int $requests): void
    {
        $this->budget = max(1, $requests);
    }

    public function requestsLeft(): int
    {
        return $this->budget;
    }

    // ------------------------------------------------------------------ tornei

    /**
     * @param  array<int, int>  $seasonIds
     * @param  array{0:int,1:int}|null  $scan  intervallo di id da provare uno a uno
     * @return array<string, int>
     */
    public function tournaments(array $seasonIds, ?array $scan = null): array
    {
        $stats = ['from_lists' => 0, 'scanned' => 0, 'found' => 0, 'not_a_tournament' => 0];
        $index = $this->archive->getJson('tournaments') ?? [];
        $ids = [];

        foreach ($seasonIds as $sid) {
            foreach (['ongoing', 'incoming', 'previous'] as $status) {
                $html = $this->cachedPost("league-op23-{$sid}-{$status}", 'league.php', ['op' => 23, 'sid' => $sid, 'status' => $status]);
                if ($html === null) {
                    continue;
                }
                foreach (PageParsers::tournamentList($html) as $t) {
                    $ids[$t['id']] = $t + ['season_id' => $sid, 'status' => $status];
                    $stats['from_lists']++;
                }
            }
        }

        if ($scan !== null) {
            for ($id = $scan[0]; $id <= $scan[1]; $id++) {
                $ids[$id] ??= ['id' => $id];
            }
        }

        ksort($ids);
        foreach ($ids as $id => $listed) {
            if ($this->budget <= 0) {
                break;
            }
            if (! $this->refresh && isset($index[$id])) {
                continue;
            }
            $stats['scanned']++;
            $html = $this->cachedGet("tournament-{$id}", "/it/tournament/{$id}/x/stream/");
            $header = $html !== null ? PageParsers::tournamentHeader($html) : null;
            if ($header === null) {
                $stats['not_a_tournament']++;
                $index[$id] = ['id' => $id, 'exists' => false];

                continue;
            }
            $index[$id] = ['id' => $id, 'exists' => true, 'slug' => $listed['slug'] ?? Archive::slug($header['name'])] + $header + ['teams' => $listed['teams'] ?? null, 'dates' => $listed['dates'] ?? null, 'status' => $listed['status'] ?? null];
            if ($index[$id]['season_id'] === null && isset($listed['season_id'])) {
                $index[$id]['season_id'] = $listed['season_id'];
            }
            $this->archive->putJson("tournaments/{$id}/tournament", $index[$id]);
            $stats['found']++;
            ($this->say)("  torneo {$id}: {$header['name']} ({$header['season']}, {$header['sport']})");
        }

        ksort($index);
        $this->archive->putJson('tournaments', $index);
        $this->archive->markDone('tournaments', $stats);

        return $stats;
    }

    /** @return array<int, array<string, mixed>> i tornei veri, dall'indice */
    private function knownTournaments(): array
    {
        return array_filter($this->archive->getJson('tournaments') ?? [], fn (array $t) => ! empty($t['exists']));
    }

    // ------------------------------------------------------------------ dettagli dei tornei

    /** @return array<string, int> */
    public function details(): array
    {
        $stats = ['tournaments' => 0, 'fixtures' => 0, 'teams' => 0, 'skipped' => 0];

        foreach ($this->knownTournaments() as $id => $t) {
            if ($this->budget <= 0) {
                break;
            }
            if (! $this->refresh && $this->archive->isDone("details.{$id}")) {
                $stats['skipped']++;

                continue;
            }
            ($this->say)("  dettagli torneo {$id}: {$t['name']}");

            $calendar = $this->cachedGet("printable-{$id}", "/t-printable.php?t={$id}&sk=calendar");
            $fixtures = $calendar !== null ? $this->calendar->parse($calendar) : [];
            $this->archive->putJson("tournaments/{$id}/calendar", $fixtures);
            $stats['fixtures'] += count($fixtures);

            $standings = $this->cachedPost("league-op20-{$id}", 'league.php', ['op' => 20, 'tid' => $id]);
            $this->archive->putJson("tournaments/{$id}/standings", $standings !== null ? PageParsers::standings($standings) : []);

            $allStats = [];
            foreach (['score', 'top-player', 'discipline'] as $type) {
                $html = $this->cachedPost("league-op19-{$id}-{$type}", 'league.php', ['op' => 19, 'tid' => $id, 'type' => $type]);
                $allStats[$type] = $html !== null ? $this->tables->parse($html) : null;
            }
            $this->archive->putJson("tournaments/{$id}/player-stats", $allStats);

            $teams = $this->cachedPost("league-op21-{$id}", 'league.php', ['op' => 21, 'tid' => $id]);
            $teamList = $teams !== null ? PageParsers::teamList($teams) : [];
            $this->archive->putJson("tournaments/{$id}/teams", $teamList);
            $stats['teams'] += count($teamList);

            $docs = $this->cachedGet("docs-{$id}", "/it/docs/{$id}/x/");
            $this->archive->putJson("tournaments/{$id}/docs", $docs !== null ? PageParsers::docs($docs) : []);

            $this->archive->markDone("details.{$id}", ['fixtures' => count($fixtures), 'teams' => count($teamList)]);
            $stats['tournaments']++;
        }

        $this->archive->markDone('details', $stats);

        return $stats;
    }

    // ------------------------------------------------------------------ squadre, club, giocatori

    /** @return array<string, int> */
    public function teams(): array
    {
        $stats = ['teams' => 0, 'players' => 0, 'skipped' => 0];

        foreach ($this->knownTournaments() as $tid => $t) {
            foreach ($this->archive->getJson("tournaments/{$tid}/teams") ?? [] as $team) {
                if ($this->budget <= 0) {
                    break 2;
                }
                $id = (int) $team['id'];
                if (! $this->refresh && $this->archive->getJson("teams/{$id}") !== null) {
                    $stats['skipped']++;

                    continue;
                }
                $html = $this->cachedGet("team-{$id}", "/it/team/{$id}/x/");
                $page = $html !== null ? PageParsers::teamPage($html) : null;
                if ($page === null) {
                    continue;
                }
                $this->archive->putJson("teams/{$id}", ['id' => $id, 'tournament_id' => (int) $tid, 'slug' => $team['slug'] ?? null] + $page + ['badge_url' => $page['badge_url'] ?? $team['badge_url'] ?? null]);
                $stats['teams']++;
                $stats['players'] += count($page['players']);
            }
        }

        $this->archive->markDone('teams', $stats);

        return $stats;
    }

    /** @return array<string, int> */
    public function clubs(): array
    {
        $stats = ['clubs' => 0, 'profiles' => 0, 'skipped' => 0];
        $clubIds = [];

        foreach ($this->jsonFiles('teams') as $team) {
            if (! empty($team['club_id'])) {
                $clubIds[(int) $team['club_id']] = $team['club_slug'] ?? null;
            }
        }
        foreach ($this->knownTournaments() as $tid => $t) {
            foreach ($this->archive->getJson("tournaments/{$tid}/calendar") ?? [] as $f) {
                foreach (['home', 'away'] as $side) {
                    if (! empty($f[$side]['club_id'])) {
                        $clubIds[(int) $f[$side]['club_id']] ??= null;
                    }
                }
            }
        }
        ksort($clubIds);

        foreach ($clubIds as $id => $slug) {
            if ($this->budget <= 0) {
                break;
            }
            if (! $this->refresh && $this->archive->getJson("clubs/{$id}") !== null) {
                $stats['skipped']++;

                continue;
            }
            $html = $this->cachedGet("club-{$id}", "/it/team-h/{$id}/x/");
            if ($html === null) {
                continue;
            }
            $club = ['id' => $id, 'slug' => $slug] + PageParsers::clubPage($html);
            $this->archive->putJson("clubs/{$id}", $club);
            $stats['clubs']++;
            $stats['profiles'] += count($club['players']);
        }

        $this->archive->markDone('clubs', $stats);

        return $stats;
    }

    /** @return array<string, int> */
    public function players(): array
    {
        $stats = ['players' => 0, 'skipped' => 0, 'missing' => 0];
        $ids = [];
        foreach ($this->jsonFiles('clubs') as $club) {
            foreach ($club['players'] ?? [] as $p) {
                $ids[(int) $p['id']] = $p;
            }
        }
        ksort($ids);

        foreach ($ids as $id => $p) {
            if ($this->budget <= 0) {
                break;
            }
            if (! $this->refresh && $this->archive->getJson("players/{$id}") !== null) {
                $stats['skipped']++;

                continue;
            }
            $html = $this->cachedGet("player-info-{$id}", "/it/player-info/{$id}/x/");
            if ($html === null) {
                $stats['missing']++;

                continue;
            }
            try {
                $info = $this->playerInfo->parse($html);
            } catch (Throwable) {
                $stats['missing']++;

                continue;
            }
            $this->archive->putJson("players/{$id}", ['id' => $id, 'name' => $p['name'] ?? null, 'slug' => $p['slug'] ?? null, 'photo_url' => $this->firstPlayerPhoto($html)] + $info);
            $stats['players']++;
        }

        $this->archive->markDone('players', $stats);

        return $stats;
    }

    // ------------------------------------------------------------------ partite

    /** @return array<string, int> */
    public function matches(): array
    {
        $stats = ['matches' => 0, 'skipped' => 0, 'missing' => 0];

        foreach ($this->knownTournaments() as $tid => $t) {
            foreach ($this->archive->getJson("tournaments/{$tid}/calendar") ?? [] as $f) {
                if ($this->budget <= 0) {
                    break 2;
                }
                if (empty($f['played']) || empty($f['xfive_match_id'])) {
                    continue;
                }
                $id = (int) $f['xfive_match_id'];
                if (! $this->refresh && $this->archive->getJson("matches/{$id}") !== null) {
                    $stats['skipped']++;

                    continue;
                }
                $html = $this->cachedGet("match-{$id}", "/it/match/{$id}/x/");
                if ($html === null) {
                    $stats['missing']++;

                    continue;
                }
                try {
                    $report = $this->matchPage->parse($html);
                } catch (Throwable) {
                    $stats['missing']++;

                    continue;
                }
                $this->archive->putJson("matches/{$id}", ['id' => $id, 'tournament_id' => (int) $tid, 'round_label' => $f['round_label'] ?? null, 'kickoff_raw' => $f['kickoff_raw'] ?? null] + $report + ['home' => ($report['home'] ?? []) + $f['home'], 'away' => ($report['away'] ?? []) + $f['away']]);
                $stats['matches']++;
            }
        }

        $this->archive->markDone('matches', $stats);

        return $stats;
    }

    // ------------------------------------------------------------------ immagini

    /** @return array<string, int> */
    public function images(): array
    {
        $stats = ['downloaded' => 0, 'skipped' => 0, 'failed' => 0];
        $urls = [];

        foreach ($this->knownTournaments() as $tid => $t) {
            $urls[] = $t['flyer_url'] ?? null;
            foreach ($this->archive->getJson("tournaments/{$tid}/calendar") ?? [] as $f) {
                $urls[] = $f['home']['badge_url'] ?? null;
                $urls[] = $f['away']['badge_url'] ?? null;
            }
        }
        foreach ($this->jsonFiles('teams') as $team) {
            $urls[] = $team['badge_url'] ?? null;
            foreach ($team['players'] ?? [] as $p) {
                $urls[] = $p['photo_url'] ?? null;
            }
        }
        foreach ($this->jsonFiles('clubs') as $club) {
            $urls[] = $club['badge_url'] ?? null;
        }
        foreach ($this->jsonFiles('players') as $player) {
            $urls[] = $player['photo_url'] ?? null;
        }

        foreach (array_unique(array_filter($urls)) as $url) {
            if ($this->budget <= 0) {
                break;
            }
            if (str_contains(basename($url), 'ph_') || str_contains($url, '/tpl/img/')) {
                continue; // segnaposto e icone del sito
            }
            $path = self::imagePath($url);
            if ($path === null) {
                continue;
            }
            if (! $this->refresh && $this->archive->hasImage($path)) {
                $stats['skipped']++;

                continue;
            }
            $image = $this->guarded(fn () => $this->client->download($url));
            if ($image === null) {
                $stats['failed']++;

                continue;
            }
            $this->archive->putImage($path, $image['body']);
            $stats['downloaded']++;
        }

        $this->archive->markDone('images', $stats);

        return $stats;
    }

    /** Il percorso in img/ di un'immagine della CDN: la parte dopo /img/, così stemmi, foto e locandine restano divisi. */
    public static function imagePath(string $url): ?string
    {
        $p = (string) parse_url($url, PHP_URL_PATH);
        if (preg_match('~/img/(.+)$~', $p, $m)) {
            return preg_replace('~[^A-Za-z0-9/._-]~', '_', $m[1]);
        }

        return null;
    }

    // ------------------------------------------------------------------ pagine leggibili

    /** @return array<string, int> */
    public function markdown(): array
    {
        $stats = ['pages' => 0];
        $bySeason = [];

        foreach ($this->knownTournaments() as $id => $t) {
            $file = "tornei/{$id}-".Archive::slug((string) $t['name']);
            $lines = ["# {$t['name']}", '', "Stagione {$t['season']} · {$t['sport']}".(! empty($t['category']) ? " · {$t['category']}" : ''), ''];

            foreach ($this->archive->getJson("tournaments/{$id}/standings") ?? [] as $table) {
                $lines[] = '## Classifica'.($table['group'] ? " {$table['group']}" : '');
                $lines[] = '';
                $keys = array_column($table['columns'], 'key');
                $lines[] = '| # | Squadra | '.implode(' | ', $keys).' |';
                $lines[] = '|---|---|'.str_repeat('---|', count($keys));
                foreach ($table['rows'] as $r) {
                    $lines[] = "| {$r['position']} | {$r['name']} | ".implode(' | ', array_map(fn ($k) => $r['values'][$k] ?? '', $keys)).' |';
                }
                $lines[] = '';
            }

            $fixtures = $this->archive->getJson("tournaments/{$id}/calendar") ?? [];
            if ($fixtures) {
                $lines[] = '## Calendario e risultati';
                $lines[] = '';
                $round = null;
                foreach ($fixtures as $f) {
                    if (($f['round_label'] ?? null) !== $round) {
                        $round = $f['round_label'] ?? null;
                        $lines[] = "### {$round}";
                        $lines[] = '';
                    }
                    $score = ! empty($f['played']) ? "{$f['home_score']}-{$f['away_score']}" : 'da giocare';
                    $lines[] = "- {$f['home']['name']} vs {$f['away']['name']}: {$score}".(! empty($f['kickoff_raw']) ? " ({$f['kickoff_raw']})" : '').(! empty($f['venue']) ? " · {$f['venue']}" : '');
                }
                $lines[] = '';
            }

            $teams = $this->archive->getJson("tournaments/{$id}/teams") ?? [];
            if ($teams) {
                $lines[] = '## Squadre';
                $lines[] = '';
                foreach ($teams as $team) {
                    $page = $this->archive->getJson("teams/{$team['id']}");
                    $players = $page ? array_map(fn ($p) => trim((! empty($p['number']) ? "{$p['number']} " : '').($p['name'] ?? '')), $page['players'] ?? []) : [];
                    $lines[] = "- **{$team['name']}**".($players ? ': '.implode(', ', $players) : '');
                }
                $lines[] = '';
            }

            $this->archive->putMarkdown($file, implode("\n", $lines));
            $bySeason[$t['season'] ?? '?'][] = "- [{$t['name']}]({$file}.md) · {$t['sport']}";
            $stats['pages']++;
        }

        krsort($bySeason);
        $index = ['# Archivio XFive', ''];
        foreach ($bySeason as $season => $items) {
            $index[] = "## Stagione {$season}";
            $index[] = '';
            array_push($index, ...$items);
            $index[] = '';
        }
        $this->archive->putMarkdown('index', implode("\n", $index));
        $this->archive->markDone('markdown', $stats);

        return $stats;
    }

    // ------------------------------------------------------------------ servizio

    /** @return array<int, array<string, mixed>> tutti i file json/{dir}/*.json */
    private function jsonFiles(string $dir): array
    {
        $out = [];
        foreach (glob($this->archive->root()."/json/{$dir}/*.json") ?: [] as $file) {
            $out[] = json_decode((string) file_get_contents($file), true) ?: [];
        }

        return $out;
    }

    private function firstPlayerPhoto(string $html): ?string
    {
        return preg_match('~https://cdn\.enjore\.com/[^"\']*/img/player/[^"\']+~', $html, $m) ? $m[0] : null;
    }

    /** Una pagina: dalla cache raw/ se c'è, altrimenti dal sito (null se non esiste). */
    private function cachedGet(string $key, string $path): ?string
    {
        if (! $this->refresh && ($html = $this->archive->getRaw($key)) !== null) {
            return $html;
        }
        $html = $this->guarded(fn () => $this->client->get($path));
        if ($html !== null) {
            $this->archive->putRaw($key, $html);
        }

        return $html;
    }

    /** Una chiamata interna: l'HTML dentro la risposta JSON, dalla cache se c'è. */
    private function cachedPost(string $key, string $script, array $data): ?string
    {
        if (! $this->refresh && ($json = $this->archive->getRaw($key, 'json')) !== null) {
            $obj = json_decode($json, true);

            return is_string($obj['html'] ?? null) ? $obj['html'] : null;
        }
        $obj = $this->guarded(fn () => $this->client->post('/system/include/ajax/public/'.$script, $data + ['lid' => config('amir.xfive.league_id')]));
        if ($obj === null) {
            return null;
        }
        $this->archive->putRaw($key, json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'json');

        return is_string($obj['html'] ?? null) ? $obj['html'] : null;
    }

    /**
     * Una richiesta attraverso il freno e il budget. Null se la pagina non esiste (404). Gli errori del sito fanno
     * attendere e riprovare; dopo troppi, il freno ferma tutto con un'eccezione che la tappa lascia salire.
     *
     * @template T
     *
     * @param  Closure():T  $request
     * @return T|null
     */
    private function guarded(Closure $request): mixed
    {
        $this->budget--;
        try {
            return $this->limiter->run($request, fn (Throwable $e) => str_contains($e->getMessage(), 'HTTP 404'));
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }
    }
}
