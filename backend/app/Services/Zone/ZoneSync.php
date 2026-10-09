<?php

namespace App\Services\Zone;

use App\Models\Zone\XfMatch;
use App\Models\Zone\XfPlayer;
use App\Models\Zone\XfSeason;
use App\Models\Zone\XfSyncState;
use App\Models\Zone\XfTeamPlayer;
use App\Models\Zone\XfTournament;
use App\Services\Xfive\Archive\Limiter;
use App\Services\Xfive\Archive\PageParsers;
use App\Services\Xfive\MatchPageParser;
use App\Services\Xfive\PlayerInfoParser;
use App\Services\Xfive\PrintableCalendarParser;
use App\Services\Xfive\StatsTableParser;
use App\Services\Xfive\XfiveClient;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Gli aggiornamenti dal vivo della Mixed Zone: rilegge da XFive, con i lettori dell'archivio, e scrive nelle tabelle
 * xf_* attraverso ZoneImporter. Ogni sezione lavora a pezzi entro una scadenza (sul server una richiesta dura poco) e
 * tiene in xf_sync_state (section = nome dello scope) il cursore del giro in corso: il pezzo dopo riparte da lì senza
 * rifare quello che ha già letto; a giro finito il cursore si azzera e restano synced_at e i conteggi.
 *
 *   zone-tournaments  elenchi per stagione (league.php op=23) e intestazioni dei tornei di calcio nuovi; aggiorna lo stato degli altri
 *   zone-calendar     calendario stampabile dei tornei attivi (ongoing/incoming), prima quelli mai letti poi i più vecchi
 *   zone-standings    classifiche (op=20) dei tornei attivi
 *   zone-stats        marcatori, miglior giocatore, disciplina (op=19 x3) dei tornei attivi
 *   zone-teams        squadre iscritte (op=21) e rose dei tornei attivi, più la pagina del club quando la rosa ha giocatori senza profilo
 *   zone-reports      referti delle partite giocate che non ne hanno ancora uno (prima i tornei attivi)
 *   zone-players      profili globali mancanti o non riletti da 30 giorni
 *
 * Ogni metodo restituisce i conteggi di QUESTO pezzo (interi, chiavi distinte) più «requests», «errors» e «remaining»
 * (quanti elementi restano: 0 = giro finito). $progress(string $message, int $done, int $total) racconta ogni passo.
 * Freno: una richiesta al secondo (Limiter); gli errori del sito non fanno aspettare (non c'è tempo nel budget):
 * si salta l'elemento e si va avanti, dopo 3 errori di fila ci si ferma.
 */
final class ZoneSync
{
    public const SECTIONS = ['zone-tournaments', 'zone-calendar', 'zone-standings', 'zone-stats', 'zone-teams', 'zone-reports', 'zone-players'];

    /** Le sezioni di ogni notte, nell'ordine; quelle settimanali si accodano il lunedì. */
    public const NIGHTLY = ['zone-tournaments', 'zone-calendar', 'zone-standings', 'zone-stats', 'zone-reports'];

    public const WEEKLY = ['zone-teams', 'zone-players'];

    private const ACTIVE = ['ongoing', 'incoming'];

    private const MAX_CONSECUTIVE_ERRORS = 3;

    private const STATUS_LABELS = ['ongoing' => 'in corso', 'incoming' => 'in arrivo', 'previous' => 'conclusi'];

    private int $requests = 0;

    private int $errors = 0;

    private int $consecutiveErrors = 0;

    /** @var array<int, string> id stagione => etichetta (2026/2027) */
    private array $seasonLabels = [];

    public function __construct(
        private readonly XfiveClient $client,
        private readonly PrintableCalendarParser $calendarParser,
        private readonly StatsTableParser $tables,
        private readonly MatchPageParser $matchPage,
        private readonly PlayerInfoParser $playerInfo,
        private readonly ZoneImporter $importer,
        private readonly Limiter $limiter = new Limiter(gap: 1.0),
    ) {}

    /** Le sezioni da fare in fila in una notte: quelle di ogni notte e, se $weekly, anche squadre e profili. */
    public static function nightly(bool $weekly): array
    {
        return $weekly ? [...self::NIGHTLY, ...self::WEEKLY] : self::NIGHTLY;
    }

    /**
     * Una sezione per nome (zone-calendar...).
     *
     * @return array<string, int>
     */
    public function section(string $section, float $deadline, Closure $progress): array
    {
        return match ($section) {
            'zone-tournaments' => $this->tournaments($deadline, $progress),
            'zone-calendar' => $this->calendar($deadline, $progress),
            'zone-standings' => $this->standings($deadline, $progress),
            'zone-stats' => $this->stats($deadline, $progress),
            'zone-teams' => $this->teams($deadline, $progress),
            'zone-reports' => $this->reports($deadline, $progress),
            'zone-players' => $this->players($deadline, $progress),
            default => throw new RuntimeException("Sezione sconosciuta: {$section}."),
        };
    }

    // ------------------------------------------------------------------ tornei

    /**
     * Elenchi per stagione e stato (op=23) per ogni stagione configurata, dalla più recente; poi le intestazioni dei
     * tornei che non abbiamo (solo calcio: lo sport dell'elenco basta a scartare padel e pallavolo senza chiederne la
     * pagina). Lo stato (ongoing/incoming/previous), le squadre e le date dei tornei già in tabella si aggiornano.
     *
     * @return array<string, int>
     */
    public function tournaments(float $deadline, Closure $progress): array
    {
        $state = $this->state('zone-tournaments');
        $cursor = $state->cursor ?? [];
        $lists = $cursor['lists'] ?? []; // «sid-status» già letti in questo giro
        $listed = $cursor['listed'] ?? []; // id => dati dall'elenco
        $skipped = $cursor['skipped'] ?? []; // pagine che non sono tornei (o non di calcio): non si riprovano nel giro
        $refreshed = (bool) ($cursor['refreshed'] ?? false);
        $round = $cursor['counts'] ?? [];
        $run = [];

        $save = function () use ($state, &$lists, &$listed, &$skipped, &$refreshed, &$round): void {
            $state->forceFill(['cursor' => ['lists' => $lists, 'listed' => $listed, 'skipped' => $skipped, 'refreshed' => $refreshed, 'counts' => $round]])->save();
        };

        $seasons = (array) config('amir.xfive.seasons');
        krsort($seasons);
        $pairs = [];
        foreach ($seasons as $sid => $label) {
            foreach (['ongoing', 'incoming', 'previous'] as $status) {
                $pairs[] = [(int) $sid, (string) $label, $status];
            }
        }
        $pending = array_values(array_filter($pairs, fn (array $p) => ! in_array("{$p[0]}-{$p[2]}", $lists, true)));
        foreach ($pending as $i => [$sid, $label, $status]) {
            if ($this->over($deadline)) {
                $save();

                return $this->result($run, count($pending) - $i);
            }
            $n = count($pairs) - count($pending) + $i + 1;
            $progress(sprintf('Leggo l\'elenco dei tornei %s %s (%d di %d)', self::shortSeason($label), self::STATUS_LABELS[$status], $n, count($pairs)), $n - 1, count($pairs));
            $html = $this->league(['op' => 23, 'sid' => $sid, 'status' => $status]);
            if ($html !== null) {
                foreach (PageParsers::tournamentList($html) as $t) {
                    $listed[$t['id']] = $t + ['season_id' => $sid, 'season' => $label, 'status' => $status];
                }
                $this->bump($run, $round, ['lists' => 1]);
            }
            $lists[] = "{$sid}-{$status}";
            $save();
        }

        // elenchi completi: lo stato di chi c'è già, una volta per giro
        $known = XfTournament::whereIn('id', array_keys($listed))->get()->keyBy('id');
        if (! $refreshed) {
            foreach ($listed as $id => $t) {
                $existing = $known->get($id);
                if (! $existing) {
                    continue;
                }
                $existing->fill(['status' => $t['status'], 'teams_count' => $t['teams'] ?? $existing->teams_count, 'dates' => $t['dates'] ?? $existing->dates]);
                if ($existing->isDirty()) {
                    $existing->save();
                    $this->bump($run, $round, ['updated' => 1]);
                }
            }
            $refreshed = true;
            $save();
        }

        $todo = [];
        foreach ($listed as $id => $t) {
            if ($known->has($id) || in_array($id, $skipped, true)) {
                continue;
            }
            if ($t['sport'] !== null && ! ZoneImporter::isFootball((string) $t['sport'])) {
                continue; // padel, pallavolo: niente da chiedere
            }
            $todo[] = (int) $id;
        }
        sort($todo);
        foreach ($todo as $i => $id) {
            if ($this->over($deadline)) {
                $save();

                return $this->result($run, count($todo) - $i);
            }
            $t = $listed[$id];
            $progress(sprintf('Leggo il torneo %s (%s) (%d di %d)', $t['name'] ?? $id, self::shortSeason($t['season']), $i + 1, count($todo)), $i, count($todo));
            $html = $this->fetch(fn () => $this->client->get("/it/tournament/{$id}/x/stream/"));
            $header = $html !== null ? PageParsers::tournamentHeader($html) : null;
            if ($header === null) {
                $skipped[] = $id;
                $this->bump($run, $round, ['skipped' => 1]);
                $save();

                continue;
            }
            $row = ['id' => $id, 'exists' => true, 'slug' => $t['slug'] ?? Str::slug($header['name'])] + $header + ['teams' => $t['teams'] ?? null, 'dates' => $t['dates'] ?? null, 'status' => $t['status']];
            $row['season_id'] ??= $t['season_id'];
            $row['season'] ??= $t['season'];
            if ($this->importer->tournament($row)) {
                $this->bump($run, $round, ['tournaments' => 1]);
            } else {
                $skipped[] = $id;
                $this->bump($run, $round, ['skipped' => 1]);
            }
            $save();
        }

        $this->finish($state, $round);

        return $this->result($run, 0);
    }

    // ------------------------------------------------------------------ calendari, classifiche, statistiche

    /** @return array<string, int> */
    public function calendar(float $deadline, Closure $progress): array
    {
        return $this->tournamentRound('zone-calendar', 'calendar_synced_at', $deadline, $progress, 'Leggo il calendario di', function (XfTournament $t): ?array {
            $html = $this->fetch(fn () => $this->client->printableCalendar($t->id));

            return $html === null ? null : $this->importer->calendar($t->id, $this->calendarParser->parse($html), $this->seasonLabel($t->season_id));
        });
    }

    /** @return array<string, int> */
    public function standings(float $deadline, Closure $progress): array
    {
        return $this->tournamentRound('zone-standings', 'standings_synced_at', $deadline, $progress, 'Leggo la classifica di', function (XfTournament $t): ?array {
            $html = $this->league(['op' => 20, 'tid' => $t->id]);

            return $html === null ? null : $this->importer->standings($t->id, PageParsers::standings($html));
        });
    }

    /** @return array<string, int> */
    public function stats(float $deadline, Closure $progress): array
    {
        return $this->tournamentRound('zone-stats', 'stats_synced_at', $deadline, $progress, 'Leggo le statistiche di', function (XfTournament $t): ?array {
            $tables = [];
            foreach (['score', 'top-player', 'discipline'] as $type) {
                $html = $this->league(['op' => 19, 'tid' => $t->id, 'type' => $type]);
                if ($html !== null) {
                    $tables[$type] = $this->tables->parse($html);
                }
            }

            return $tables === [] ? null : $this->importer->playerStats($t->id, $tables);
        });
    }

    /**
     * Un giro sui tornei attivi: prima quelli mai letti ($column null), poi i più vecchi. Il cursore ricorda quali
     * sono già stati fatti in questo giro, così il pezzo dopo non li rilegge; $read torna i conteggi o null se la
     * pagina non c'era (il torneo si segna comunque come fatto nel giro).
     *
     * @param  Closure(XfTournament):?array  $read
     * @return array<string, int>
     */
    private function tournamentRound(string $section, string $column, float $deadline, Closure $progress, string $verb, Closure $read): array
    {
        $state = $this->state($section);
        $cursor = $state->cursor ?? [];
        $done = array_map('intval', $cursor['done'] ?? []);
        $round = $cursor['counts'] ?? [];
        $run = [];
        $queue = $this->activeTournaments($column, $done);
        $total = count($done) + count($queue);

        $save = function () use ($state, &$done, &$round, $total): void {
            $state->forceFill(['cursor' => ['done' => $done, 'total' => $total, 'counts' => $round]])->save();
        };
        $save(); // anche senza tempo per leggere nulla il giro è iniziato

        foreach ($queue as $t) {
            if ($this->over($deadline)) {
                break;
            }
            $n = count($done) + 1;
            $progress(sprintf('%s %s (%d di %d)', $verb, $this->label($t), $n, $total), $n - 1, $total);
            $counts = $read($t);
            if ($counts !== null) {
                $this->bump($run, $round, $counts + ['tournaments' => 1]);
                $t->forceFill([$column => now()])->save();
            } else {
                $this->bump($run, $round, ['missing' => 1]);
            }
            $done[] = (int) $t->id;
            $save();
        }

        $remaining = $total - count($done);
        if ($remaining === 0) {
            $this->finish($state, $round);
        }

        return $this->result($run, $remaining);
    }

    // ------------------------------------------------------------------ squadre e rose

    /**
     * Per ogni torneo attivo: le squadre iscritte (op=21) e la pagina di ognuna (rosa, dirigenti, club); poi la pagina
     * del club quando la rosa ha giocatori ancora senza profilo globale (è il club ad abbinarli). Il cursore ricorda
     * il torneo in corso con le squadre già lette, così un pezzo interrotto a metà torneo riprende dalla squadra dopo.
     *
     * @return array<string, int>
     */
    public function teams(float $deadline, Closure $progress): array
    {
        $state = $this->state('zone-teams');
        $cursor = $state->cursor ?? [];
        $done = array_map('intval', $cursor['done'] ?? []);
        $clubsDone = array_map('intval', $cursor['clubs'] ?? []);
        $current = $cursor['current'] ?? null; // {tournament, teams: [...], teams_done: [...]}
        $round = $cursor['counts'] ?? [];
        $run = [];
        $queue = $this->activeTournaments('teams_synced_at', $done);
        $total = count($done) + count($queue);

        $save = function () use ($state, &$done, &$clubsDone, &$current, &$round, $total): void {
            $state->forceFill(['cursor' => ['done' => $done, 'clubs' => $clubsDone, 'current' => $current, 'total' => $total, 'counts' => $round]])->save();
        };
        $save();

        $stopped = false;
        foreach ($queue as $t) {
            if ($this->over($deadline)) {
                $stopped = true;
                break;
            }
            $n = count($done) + 1;
            if ($current === null || (int) $current['tournament'] !== (int) $t->id) {
                $progress(sprintf('Leggo le squadre di %s (%d di %d)', $this->label($t), $n, $total), $n - 1, $total);
                $html = $this->league(['op' => 21, 'tid' => $t->id]);
                if ($html === null) {
                    $this->bump($run, $round, ['missing' => 1]);
                    $done[] = (int) $t->id;
                    $save();

                    continue;
                }
                $current = ['tournament' => (int) $t->id, 'teams' => PageParsers::teamList($html), 'teams_done' => []];
                $save();
            }

            $teams = $current['teams'];
            foreach ($teams as $k => $team) {
                if (in_array((int) $team['id'], array_map('intval', $current['teams_done']), true)) {
                    continue;
                }
                if ($this->over($deadline)) {
                    $stopped = true;
                    break 2;
                }
                $progress(sprintf('Leggo la rosa di %s, %s (%d di %d)', $team['name'] ?? $team['id'], $this->label($t), $k + 1, count($teams)), $k, count($teams));
                $teamId = (int) $team['id'];
                $html = $this->fetch(fn () => $this->client->get("/it/team/{$teamId}/x/"));
                $page = $html !== null ? PageParsers::teamPage($html) : null;
                if ($page === null) {
                    $this->bump($run, $round, ['missing' => 1]);
                } else {
                    $this->bump($run, $round, $this->importer->team(['id' => $teamId, 'tournament_id' => (int) $t->id, 'slug' => $team['slug'] ?? null] + $page + ['badge_url' => $page['badge_url'] ?? $team['badge_url'] ?? null]));
                    $clubId = (int) ($page['club_id'] ?? 0);
                    if ($clubId > 0 && ! in_array($clubId, $clubsDone, true) && XfTeamPlayer::where('team_id', $teamId)->whereNull('player_id')->exists()) {
                        $progress(sprintf('Leggo i profili del club %s', $page['name'] ?? $team['name'] ?? $clubId), $k, count($teams));
                        $clubHtml = $this->fetch(fn () => $this->client->get("/it/team-h/{$clubId}/x/"));
                        if ($clubHtml !== null) {
                            $this->bump($run, $round, $this->importer->club(['id' => $clubId, 'slug' => $page['club_slug'] ?? null] + PageParsers::clubPage($clubHtml)));
                        }
                        $clubsDone[] = $clubId;
                    }
                }
                $current['teams_done'][] = $teamId;
                $save();
            }

            // torneo completo
            $t->forceFill(['teams_synced_at' => now()])->save();
            $this->bump($run, $round, ['tournaments' => 1]);
            $done[] = (int) $t->id;
            $current = null;
            $save();
        }

        $remaining = $total - count($done);
        if ($remaining === 0 && ! $stopped) {
            $this->finish($state, $round);
        }

        return $this->result($run, $remaining);
    }

    // ------------------------------------------------------------------ referti

    /**
     * I referti delle partite giocate senza referto, prima quelle dei tornei attivi e poi le più recenti. Una partita
     * la cui pagina manca o non si legge resta da parte fino alla fine del giro, senza bloccare le altre.
     *
     * @return array<string, int>
     */
    public function reports(float $deadline, Closure $progress): array
    {
        $state = $this->state('zone-reports');
        $cursor = $state->cursor ?? [];
        $skipped = array_map('intval', $cursor['skipped'] ?? []);
        $round = $cursor['counts'] ?? [];
        $run = [];

        $queue = XfMatch::query()
            ->join('xf_tournaments', 'xf_tournaments.id', '=', 'xf_matches.tournament_id')
            ->where('xf_matches.played', true)->where('xf_matches.has_report', false)
            ->when($skipped !== [], fn ($q) => $q->whereNotIn('xf_matches.id', $skipped))
            ->orderByRaw("case when xf_tournaments.status in ('ongoing', 'incoming') then 0 else 1 end")
            ->orderByDesc('xf_matches.kickoff_at')->orderBy('xf_matches.id')
            ->get(['xf_matches.*', 'xf_tournaments.name as tournament_name', 'xf_tournaments.season_id as tournament_season_id']);
        $total = count($queue);
        $readNow = 0;

        $save = function () use ($state, &$skipped, &$round, $total): void {
            $state->forceFill(['cursor' => ['skipped' => $skipped, 'total' => $total, 'counts' => $round]])->save();
        };
        $save();

        foreach ($queue as $i => $m) {
            if ($this->over($deadline)) {
                break;
            }
            $progress(sprintf('Leggo il referto di %s - %s, %s (%d di %d)', $m->home_name, $m->away_name, $this->tournamentLabel((string) $m->tournament_name, (int) $m->tournament_season_id), $i + 1, $total), $i, $total);
            $mid = (int) $m->id;
            $html = $this->fetch(fn () => $this->client->get("/it/match/{$mid}/x/"));
            $report = null;
            if ($html !== null) {
                try {
                    $report = $this->matchPage->parse($html);
                } catch (Throwable) {
                    $report = null;
                }
            }
            if ($report === null) {
                $skipped[] = $mid;
                $this->bump($run, $round, ['missing' => 1]);
            } else {
                $this->bump($run, $round, $this->importer->report(['id' => $mid, 'tournament_id' => (int) $m->tournament_id] + $report));
            }
            $readNow++;
            $save();
        }

        $remaining = $total - $readNow;
        if ($remaining === 0) {
            $this->finish($state, $round);
        }

        return $this->result($run, $remaining);
    }

    // ------------------------------------------------------------------ profili

    /**
     * I profili globali: prima quelli citati nelle rose (player_id) ma assenti in xf_players, poi quelli mai riletti o
     * più vecchi di 30 giorni. Il nome viene dalla rosa (Cognome Nome), se c'è, altrimenti dalla pagina.
     *
     * @return array<string, int>
     */
    public function players(float $deadline, Closure $progress): array
    {
        $state = $this->state('zone-players');
        $cursor = $state->cursor ?? [];
        $skipped = array_map('intval', $cursor['skipped'] ?? []);
        $round = $cursor['counts'] ?? [];
        $run = [];

        $missing = XfTeamPlayer::query()->whereNotNull('player_id')
            ->whereNotIn('player_id', XfPlayer::query()->select('id'))
            ->distinct()->orderBy('player_id')->pluck('player_id')->map(fn ($id) => (int) $id)->all();
        $stale = XfPlayer::query()
            ->where(fn ($q) => $q->whereNull('synced_at')->orWhere('synced_at', '<', now()->subDays(30)))
            ->orderByRaw('case when synced_at is null then 0 else 1 end')->orderBy('synced_at')->orderBy('id')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $queue = array_values(array_diff(array_unique([...$missing, ...$stale]), $skipped));
        $total = count($queue);
        $readNow = 0;

        $save = function () use ($state, &$skipped, &$round, $total): void {
            $state->forceFill(['cursor' => ['skipped' => $skipped, 'total' => $total, 'counts' => $round]])->save();
        };
        $save();

        $roster = [];
        foreach ($queue as $i => $pid) {
            if ($this->over($deadline)) {
                break;
            }
            if (! array_key_exists($pid, $roster)) {
                // nome e slug di un blocco di profili alla volta, dalle rose
                $block = array_slice($queue, $i, 50);
                $roster = array_fill_keys($block, null);
                foreach (XfTeamPlayer::whereIn('player_id', $block)->get(['player_id', 'name', 'slug']) as $row) {
                    $roster[(int) $row->player_id] ??= ['name' => (string) $row->name, 'slug' => $row->slug];
                }
            }
            $existing = XfPlayer::find($pid);
            $names = [$pid => $roster[$pid]['name'] ?? null];
            $progress(sprintf('Leggo il profilo di %s (%d di %d)', $names[$pid] ?? $existing?->name ?? $pid, $i + 1, $total), $i, $total);
            $html = $this->fetch(fn () => $this->client->get("/it/player-info/{$pid}/x/"));
            $info = null;
            if ($html !== null) {
                try {
                    $info = $this->playerInfo->parse($html);
                } catch (Throwable) {
                    $info = null;
                }
            }
            if ($info === null) {
                $skipped[] = $pid;
                $this->bump($run, $round, ['missing' => 1]);
            } else {
                $name = $names[$pid] ?? $existing?->name ?? $this->pageName($html) ?? (string) $pid;
                $photo = $this->playerPhoto($html) ?? $existing?->photo_url;
                $slug = $roster[$pid]['slug'] ?? $existing?->slug;
                $this->bump($run, $round, $this->importer->player(['id' => $pid, 'name' => $name, 'slug' => $slug, 'photo_url' => $photo] + $info));
            }
            $readNow++;
            $save();
        }

        $remaining = $total - $readNow;
        if ($remaining === 0) {
            $this->finish($state, $round);
        }

        return $this->result($run, $remaining);
    }

    // ------------------------------------------------------------------ servizio

    /** I tornei attivi non ancora fatti in questo giro: prima quelli con $column null, poi i più vecchi. */
    private function activeTournaments(string $column, array $exclude): Collection
    {
        return XfTournament::query()
            ->whereIn('status', self::ACTIVE)
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('id', $exclude))
            ->orderByRaw("case when {$column} is null then 0 else 1 end")
            ->orderBy($column)->orderBy('id')
            ->get();
    }

    private function state(string $section): XfSyncState
    {
        return XfSyncState::firstOrNew(['section' => $section]);
    }

    /** Giro finito: via il cursore, restano la data e i conteggi di tutto il giro. */
    private function finish(XfSyncState $state, array $roundCounts): void
    {
        $state->forceFill(['cursor' => null, 'synced_at' => now(), 'counts' => $roundCounts])->save();
    }

    /** Somma dei conteggi sia su questo pezzo sia sul giro. */
    private function bump(array &$run, array &$round, array $counts): void
    {
        foreach ($counts as $k => $v) {
            $run[$k] = ($run[$k] ?? 0) + (int) $v;
            $round[$k] = ($round[$k] ?? 0) + (int) $v;
        }
    }

    /** @return array<string, int> */
    private function result(array $run, int $remaining): array
    {
        $out = $run + ['requests' => $this->requests, 'errors' => $this->errors, 'remaining' => max(0, $remaining)];
        $this->requests = $this->errors = 0;

        return $out;
    }

    private function over(float $deadline): bool
    {
        return microtime(true) >= $deadline;
    }

    /** Una chiamata interna di XFive (league.php): l'HTML dentro la risposta JSON, null se manca o la pagina non c'è. */
    private function league(array $data): ?string
    {
        $obj = $this->fetch(fn () => $this->client->post('/system/include/ajax/public/league.php', $data + ['lid' => config('amir.xfive.league_id')]));

        return is_array($obj) && is_string($obj['html'] ?? null) ? $obj['html'] : null;
    }

    /**
     * Una richiesta attraverso il freno. Null se la pagina non esiste (404) o se il sito ha risposto male: l'elemento
     * si salta e si va avanti (nel budget di una richiesta non c'è tempo per aspettare); dopo 3 errori di fila ci si ferma.
     *
     * @template T
     *
     * @param  Closure():T  $request
     * @return T|null
     */
    private function fetch(Closure $request): mixed
    {
        $this->requests++;
        try {
            // al freno diciamo che ogni errore è «nostro»: così non dorme e non riprova, decidiamo noi qui sotto
            $result = $this->limiter->run($request, fn () => true);
            $this->consecutiveErrors = 0;

            return $result;
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                $this->consecutiveErrors = 0;

                return null;
            }
            $this->errors++;
            if (++$this->consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
                throw new RuntimeException('XFive risponde male a '.self::MAX_CONSECUTIVE_ERRORS.' richieste di fila: mi fermo. Riprova più tardi.', 0, $e);
            }

            return null;
        }
    }

    /** «CITTADELLA 2026/27» */
    private function label(XfTournament $t): string
    {
        return $this->tournamentLabel((string) $t->name, (int) $t->season_id);
    }

    private function tournamentLabel(string $name, int $seasonId): string
    {
        $season = $this->seasonLabel($seasonId);

        return trim($name.($season !== '' ? ' '.self::shortSeason($season) : ''));
    }

    private function seasonLabel(int $seasonId): string
    {
        if ($this->seasonLabels === []) {
            $this->seasonLabels = XfSeason::query()->pluck('label', 'id')->map(fn ($l) => (string) $l)->all() + array_map('strval', (array) config('amir.xfive.seasons'));
        }

        return $this->seasonLabels[$seasonId] ?? '';
    }

    /** «2026/2027» → «2026/27» */
    public static function shortSeason(string $label): string
    {
        return preg_replace('~^(\d{4})/\d{2}(\d{2})$~', '$1/$2', $label) ?? $label;
    }

    /** Il nome in testa alla pagina del profilo, se c'è. */
    private function pageName(string $html): ?string
    {
        $node = (new Crawler(PageParsers::utf8($html)))->filter('.wl-playername h3');
        $name = $node->count() ? trim(preg_replace('/\s+/', ' ', $node->first()->text('')) ?? '') : '';

        return $name !== '' ? $name : null;
    }

    /** La prima foto vera del giocatore nella pagina (non il segnaposto ph_). */
    private function playerPhoto(string $html): ?string
    {
        if (preg_match_all('~https://cdn\.enjore\.com/[^"\']*/img/player/[^"\']+~', $html, $m)) {
            foreach ($m[0] as $url) {
                if (! str_contains(basename($url), 'ph_')) {
                    return $url;
                }
            }
        }

        return null;
    }
}
