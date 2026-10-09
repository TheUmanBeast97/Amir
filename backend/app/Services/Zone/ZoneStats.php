<?php

namespace App\Services\Zone;

use App\Models\Zone\XfClub;
use App\Models\Zone\XfMatch;
use App\Models\Zone\XfMatchPlayer;
use App\Models\Zone\XfPlayer;
use App\Models\Zone\XfPlayerStat;
use App\Models\Zone\XfTeamPlayer;
use App\Models\Zone\XfTournament;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le statistiche della Mixed Zone calcolate dai referti (xf_match_players): le classifiche di un torneo, il profilo di un
 * giocatore (totali, per stagione, compagni, vittime), le classifiche di sempre e i record dei club.
 * I calcoli pesanti stanno in SQL (una query aggregata per tabella); il resto è PHP su poche righe.
 */
final class ZoneStats
{
    /** Le colonne delle tabelle XFive, che l'importer non salva: si ricavano dal tipo. */
    private const TABLE_COLUMNS = ['score' => ['Goal'], 'top-player' => ['Punti'], 'discipline' => ['Ammonizioni', 'Espulsioni']];

    /** Quante righe al massimo per ogni classifica. */
    private const TOP = 25;

    public function __construct(private readonly ZoneQueries $queries) {}

    // ------------------------------------------------------------------ torneo

    /** ZoneTournamentStats (404 se il torneo non c'è): le tabelle di XFive e, se ci sono referti, le stesse ricalcolate dai referti. */
    public function tournamentStats(int $tid): array
    {
        XfTournament::query()->findOrFail($tid);
        $rows = XfPlayerStat::query()->where('tournament_id', $tid)->orderBy('position')->get();
        $linked = $this->linkAbbreviated($tid, $rows);

        $tables = [];
        foreach (self::TABLE_COLUMNS as $type => $columns) {
            $tables[] = [
                'type' => $type,
                'columns' => $columns,
                'rows' => $rows->where('type', $type)->map(fn (XfPlayerStat $r) => [
                    'position' => (int) $r->position,
                    'name' => (string) $r->name,
                    'team' => $r->team,
                    'photo_url' => $r->photo_url,
                    'player_id' => $linked[$r->id] ?? null,
                    'values' => array_map(fn ($v) => is_numeric($v) ? $v + 0 : 0, array_values((array) $r->values)),
                ])->values()->all(),
            ];
        }

        $hasReports = XfMatch::query()->where('tournament_id', $tid)->where('has_report', true)->exists();

        return ['tables' => $tables, 'from_reports' => $hasReports ? $this->fromReports($tid) : null];
    }

    /**
     * Prova ad abbinare le righe XFive («Verdi L.» di «CAFFÈ KM0») al profilo globale attraverso le rose del torneo:
     * solo quando in quella squadra c'è un solo giocatore abbinato con quel cognome e quell'iniziale.
     *
     * @return array<int,int> id riga => player_id
     */
    private function linkAbbreviated(int $tid, Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $roster = XfTeamPlayer::query()
            ->join('xf_teams', 'xf_teams.id', '=', 'xf_team_players.team_id')
            ->where('xf_teams.tournament_id', $tid)->whereNotNull('xf_team_players.player_id')
            ->get(['xf_team_players.player_id', 'xf_team_players.name', 'xf_teams.name as team']);

        $index = [];
        foreach ($roster as $p) {
            $parts = preg_split('/\s+/', ZoneImporter::normalize((string) $p->name)) ?: [];
            if (count($parts) < 2) {
                continue;
            }
            // il nome XFive è «Cognome Nome»: la riga abbreviata è «Cognome N.»
            $key = ZoneImporter::normalize((string) $p->team).'|'.$parts[0].' '.mb_substr($parts[1], 0, 1);
            $index[$key][] = (int) $p->player_id;
        }

        $linked = [];
        foreach ($rows as $r) {
            $abbr = rtrim(ZoneImporter::normalize((string) $r->name), '.');
            $key = ZoneImporter::normalize((string) $r->team).'|'.$abbr;
            $ids = array_unique($index[$key] ?? []);
            if (count($ids) === 1) {
                $linked[$r->id] = (int) reset($ids);
            }
        }

        return $linked;
    }

    /** Marcatori, migliori giocatori (MVP) e cartellini dai referti del torneo, con i link ai profili. */
    private function fromReports(int $tid): array
    {
        $agg = $this->aggregate(fn (Query $q) => $q->where('m.tournament_id', $tid));
        $teams = XfTeamPlayer::query()
            ->join('xf_teams', 'xf_teams.id', '=', 'xf_team_players.team_id')
            ->where('xf_teams.tournament_id', $tid)->whereIn('xf_team_players.player_id', $agg->pluck('player_id')->all())
            ->pluck('xf_teams.name', 'xf_team_players.player_id')->all();

        return $this->boards($agg, $teams);
    }

    /**
     * La query aggregata per giocatore (solo righe con il profilo abbinato): presenze, gol, gialli, rossi, premi MVP.
     *
     * @param  callable(Query): Query  $scope
     */
    private function aggregate(callable $scope): Collection
    {
        $q = DB::table('xf_match_players as mp')
            ->join('xf_matches as m', 'm.id', '=', 'mp.match_id')
            ->join('xf_players as p', 'p.id', '=', 'mp.player_id')
            ->whereNotNull('mp.player_id')
            ->groupBy('mp.player_id', 'p.name', 'p.photo_url')
            ->selectRaw('mp.player_id as player_id, p.name as name, p.photo_url as photo_url, count(*) as matches, sum(mp.goals) as goals, sum(mp.yellow) as yellow, sum(mp.red) as red, sum(case when mp.mvp then 1 else 0 end) as mvp');
        $scope($q);

        return $q->get();
    }

    /**
     * Le classifiche dai dati aggregati: marcatori, MVP, cartellini e (per le statistiche di sempre) presenze.
     *
     * @param  array<int,string>  $teams  player_id => nome squadra
     */
    private function boards(Collection $agg, array $teams): array
    {
        $row = fn (object $a, array $values) => [
            'name' => (string) $a->name,
            'team' => $teams[(int) $a->player_id] ?? null,
            'photo_url' => $a->photo_url,
            'player_id' => (int) $a->player_id,
            'values' => $values,
        ];
        $rank = fn (Collection $rows) => $rows->take(self::TOP)->values()->map(fn (array $r, int $i) => ['position' => $i + 1] + $r)->all();

        return [
            'scorers' => $rank($agg->filter(fn ($a) => (int) $a->goals > 0)
                ->sort(fn ($a, $b) => [(int) $b->goals, (int) $a->matches, $a->name] <=> [(int) $a->goals, (int) $b->matches, $b->name])
                ->map(fn ($a) => $row($a, [(int) $a->goals]))),
            'mvp' => $rank($agg->filter(fn ($a) => (int) $a->mvp > 0)
                ->sort(fn ($a, $b) => [(int) $b->mvp, (int) $a->matches, $a->name] <=> [(int) $a->mvp, (int) $b->matches, $b->name])
                ->map(fn ($a) => $row($a, [(int) $a->mvp]))),
            'cards' => $rank($agg->filter(fn ($a) => (int) $a->yellow + (int) $a->red > 0)
                ->sort(fn ($a, $b) => [(int) $b->yellow + (int) $b->red, (int) $b->red, $a->name] <=> [(int) $a->yellow + (int) $a->red, (int) $a->red, $b->name])
                ->map(fn ($a) => $row($a, [(int) $a->yellow, (int) $a->red]))),
            'appearances' => $rank($agg
                ->sort(fn ($a, $b) => [(int) $b->matches, (int) $b->goals, $a->name] <=> [(int) $a->matches, (int) $a->goals, $b->name])
                ->map(fn ($a) => $row($a, [(int) $a->matches]))),
        ];
    }

    // ------------------------------------------------------------------ giocatore

    /** ZonePlayerPage (404 se il profilo non c'è): club e tornei, totali dai referti, per stagione, compagni, vittime, ultime partite. */
    public function player(int $pid): array
    {
        $p = XfPlayer::query()->findOrFail($pid);
        $labels = $this->queries->seasonLabels();

        $rows = DB::table('xf_match_players as mp')
            ->join('xf_matches as m', 'm.id', '=', 'mp.match_id')
            ->join('xf_tournaments as t', 't.id', '=', 'm.tournament_id')
            ->where('mp.player_id', $pid)
            ->get(['mp.side', 'mp.goals', 'mp.yellow', 'mp.red', 'mp.mvp', 'm.played', 'm.home_score', 'm.away_score', 'm.home_club_id', 'm.away_club_id', 't.season_id']);

        $totals = ['matches' => 0, 'goals' => 0, 'yellow' => 0, 'red' => 0, 'mvp' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0];
        $bySeason = [];
        $victims = [];
        foreach ($rows as $r) {
            $home = $r->side === 'home';
            $for = (int) ($home ? $r->home_score : $r->away_score);
            $against = (int) ($home ? $r->away_score : $r->home_score);
            $totals['matches']++;
            $totals['goals'] += (int) $r->goals;
            $totals['yellow'] += (int) $r->yellow;
            $totals['red'] += (int) $r->red;
            $totals['mvp'] += $r->mvp ? 1 : 0;
            if ($r->played) {
                $totals[$for > $against ? 'wins' : ($for === $against ? 'draws' : 'losses')]++;
            }

            $season = $labels[(int) $r->season_id] ?? '';
            $s = &$bySeason[$season];
            $s ??= ['season' => $season, 'matches' => 0, 'goals' => 0, 'yellow' => 0, 'red' => 0, 'mvp' => 0];
            $s['matches']++;
            $s['goals'] += (int) $r->goals;
            $s['yellow'] += (int) $r->yellow;
            $s['red'] += (int) $r->red;
            $s['mvp'] += $r->mvp ? 1 : 0;
            unset($s);

            $opponent = (int) ($home ? $r->away_club_id : $r->home_club_id);
            if ($opponent) {
                $v = &$victims[$opponent];
                $v ??= ['goals' => 0, 'matches' => 0];
                $v['goals'] += (int) $r->goals;
                $v['matches']++;
                unset($v);
            }
        }
        krsort($bySeason);
        $totals['goals_per_match'] = $totals['matches'] ? round($totals['goals'] / $totals['matches'], 2) : 0;

        $victims = collect($victims)->filter(fn (array $v) => $v['goals'] > 0)
            ->sortBy(fn (array $v, int $club) => [-$v['goals'], -$v['matches'], $club])->take(10);
        $victimClubs = $victims->isEmpty() ? collect() : XfClub::query()->findMany($victims->keys()->all())->keyBy('id');

        return [
            'player' => ZoneQueries::playerRef($p) + ['age' => $p->age !== null ? (int) $p->age : null, 'clubs' => $this->playerClubs($p)],
            'totals' => $totals,
            'by_season' => array_values($bySeason),
            'partners' => $this->partners($pid),
            'victims' => $victims->map(fn (array $v, int $club) => [
                'club' => ZoneQueries::clubRef($club, (string) ($victimClubs->get($club)?->name ?? ''), $victimClubs->get($club)?->badge_url),
                'goals' => $v['goals'],
                'matches' => $v['matches'],
            ])->values()->all(),
            'recent' => $this->recent($pid),
        ];
    }

    /** I club e i tornei (di calcio, presenti nelle tabelle) dal profilo XFive; senza profilo, dalle rose. */
    private function playerClubs(XfPlayer $p): array
    {
        $profile = collect($p->profile ?? [])->filter(fn ($c) => is_array($c) && ! empty($c['club_id']));
        if ($profile->isEmpty()) {
            $profile = XfTeamPlayer::query()->join('xf_teams', 'xf_teams.id', '=', 'xf_team_players.team_id')
                ->where('xf_team_players.player_id', $p->id)->whereNotNull('xf_teams.club_id')
                ->get(['xf_teams.club_id', 'xf_teams.name', 'xf_teams.tournament_id'])
                ->groupBy('club_id')
                ->map(fn (Collection $g, $clubId) => ['club_id' => (int) $clubId, 'name' => (string) $g->first()->name, 'tournaments' => $g->map(fn ($t) => ['id' => (int) $t->tournament_id])->all()])
                ->values();
        }

        $clubs = XfClub::query()->findMany($profile->pluck('club_id')->map(fn ($v) => (int) $v)->all())->keyBy('id');
        $tids = $profile->flatMap(fn (array $c) => collect($c['tournaments'] ?? [])->pluck('id'))->filter()->map(fn ($v) => (int) $v)->unique()->all();
        $tournaments = $tids ? XfTournament::query()->findMany($tids)->keyBy('id') : collect();

        return $profile->map(function (array $c) use ($clubs, $tournaments) {
            $club = $clubs->get((int) $c['club_id']);

            return [
                'club' => ZoneQueries::clubRef((int) $c['club_id'], (string) ($club?->name ?? $c['name'] ?? ''), $club?->badge_url),
                'tournaments' => collect($c['tournaments'] ?? [])
                    ->map(fn ($t) => $tournaments->get((int) ($t['id'] ?? 0)))->filter()
                    ->sortByDesc(fn (XfTournament $t) => (int) $t->season_id.'-'.str_pad((string) $t->id, 8, '0', STR_PAD_LEFT))
                    ->map(fn (XfTournament $t) => $this->queries->tournamentRef($t))->values()->all(),
            ];
        })->values()->all();
    }

    /** I compagni con almeno 6 partite insieme, ordinati per punti a partita (3 vittoria, 1 pareggio). */
    private function partners(int $pid): array
    {
        $rows = DB::table('xf_match_players as me')
            ->join('xf_match_players as mate', fn ($j) => $j->on('mate.match_id', '=', 'me.match_id')->on('mate.side', '=', 'me.side'))
            ->join('xf_matches as m', 'm.id', '=', 'me.match_id')
            ->join('xf_players as p', 'p.id', '=', 'mate.player_id')
            ->where('me.player_id', $pid)->where('mate.player_id', '!=', $pid)->where('m.played', true)
            ->groupBy('mate.player_id', 'p.name', 'p.photo_url', 'p.nationality')
            ->havingRaw('count(*) >= 6')
            ->selectRaw("mate.player_id as id, p.name as name, p.photo_url as photo_url, p.nationality as nationality, count(*) as played, "
                ."sum(case when (me.side = 'home' and m.home_score > m.away_score) or (me.side = 'away' and m.away_score > m.home_score) then 1 else 0 end) as won, "
                .'sum(case when m.home_score = m.away_score then 1 else 0 end) as drawn')
            ->get();

        return $rows->map(fn ($r) => [
            'player' => ['id' => (int) $r->id, 'name' => (string) $r->name, 'photo_url' => $r->photo_url, 'nationality' => $r->nationality],
            'played' => (int) $r->played,
            'won' => (int) $r->won,
            'points_per_match' => round((3 * (int) $r->won + (int) $r->drawn) / max(1, (int) $r->played), 2),
        ])->sortBy(fn (array $r) => [-$r['points_per_match'], -$r['played'], $r['player']['name']])->take(10)->values()->all();
    }

    /** Le ultime 10 partite del giocatore, con i suoi gol e il premio MVP. */
    private function recent(int $pid): array
    {
        $matches = XfMatch::query()
            ->join('xf_match_players as mp', 'mp.match_id', '=', 'xf_matches.id')
            ->where('mp.player_id', $pid)
            ->orderByRaw('xf_matches.kickoff_at is null')->orderByDesc('xf_matches.kickoff_at')->orderByDesc('xf_matches.id')
            ->limit(10)
            ->get(['xf_matches.*', 'mp.goals as my_goals', 'mp.mvp as my_mvp']);

        $json = $this->queries->matches($matches);
        foreach ($matches->values() as $i => $m) {
            $json[$i]['goals'] = (int) $m->my_goals;
            $json[$i]['mvp'] = (bool) $m->my_mvp;
        }

        return $json;
    }

    // ------------------------------------------------------------------ classifiche di sempre

    /** ZoneStatsBoard: le classifiche di sempre dai referti, con i filtri stagione (etichetta) e sport. */
    public function board(?string $season, ?string $sport): array
    {
        $seasonId = $season !== null && $season !== '' ? array_search($season, $this->queries->seasonLabels(), true) : null;
        $sport = $sport !== null && $sport !== '' ? $sport : null;
        $scope = function (Query $q) use ($seasonId, $sport): Query {
            $q->join('xf_tournaments as t', 't.id', '=', 'm.tournament_id');
            if ($seasonId !== null) {
                $q->where('t.season_id', $seasonId === false ? -1 : $seasonId);
            }
            if ($sport !== null) {
                $q->where('t.sport', $sport);
            }

            return $q;
        };

        $agg = $this->aggregate($scope);
        $top = $agg->sortByDesc('matches')->take(self::TOP)->pluck('player_id')
            ->merge($agg->sortByDesc('goals')->take(self::TOP)->pluck('player_id'))
            ->merge($agg->sortByDesc('mvp')->take(self::TOP)->pluck('player_id'))
            ->merge($agg->sortByDesc(fn ($a) => (int) $a->yellow + (int) $a->red)->take(self::TOP)->pluck('player_id'))
            ->unique()->all();
        // la squadra: quella dell'ultimo torneo in cui il giocatore è stato in rosa
        $teams = [];
        if ($top) {
            foreach (XfTeamPlayer::query()->join('xf_teams', 'xf_teams.id', '=', 'xf_team_players.team_id')
                ->whereIn('xf_team_players.player_id', $top)->orderByDesc('xf_teams.tournament_id')
                ->get(['xf_team_players.player_id', 'xf_teams.name']) as $t) {
                $teams[(int) $t->player_id] ??= (string) $t->name;
            }
        }
        $boards = $this->boards($agg, $teams);

        // i club: due righe per partita giocata (casa e trasferta), aggregate per club
        $side = fn (string $mine, string $other) => $scope(DB::table('xf_matches as m'))
            ->where('m.played', true)->whereNotNull("m.{$mine}_club_id")
            ->selectRaw("m.{$mine}_club_id as club_id, m.{$mine}_score as gf, m.{$other}_score as ga");
        $clubs = DB::query()->fromSub($side('home', 'away')->unionAll($side('away', 'home')), 'x')
            ->groupBy('club_id')->havingRaw('count(*) >= 10')
            ->selectRaw('club_id, count(*) as played, sum(case when gf > ga then 1 else 0 end) as won, sum(case when gf = ga then 1 else 0 end) as drawn, sum(ga) as conceded')
            ->get();
        $refs = $clubs->isEmpty() ? collect() : XfClub::query()->findMany($clubs->pluck('club_id')->all())->keyBy('id');
        $clubRef = fn ($c) => ZoneQueries::clubRef((int) $c->club_id, (string) ($refs->get((int) $c->club_id)?->name ?? ''), $refs->get((int) $c->club_id)?->badge_url);

        return [
            'seasons' => $this->queries->seasons(),
            'sports' => XfTournament::query()->distinct()->orderBy('sport')->pluck('sport')->map(fn ($s) => (string) $s)->all(),
            'scorers' => $boards['scorers'],
            'appearances' => $boards['appearances'],
            'cards' => $boards['cards'],
            'mvp' => $boards['mvp'],
            'best_teams' => $clubs->map(fn ($c) => [
                'club' => $clubRef($c),
                'played' => (int) $c->played,
                'won' => (int) $c->won,
                'points_per_match' => round((3 * (int) $c->won + (int) $c->drawn) / (int) $c->played, 2),
            ])->sortBy(fn (array $r) => [-$r['points_per_match'], -$r['played'], $r['club']['name']])->take(self::TOP)->values()->all(),
            'best_defenses' => $clubs->map(fn ($c) => [
                'club' => $clubRef($c),
                'played' => (int) $c->played,
                'conceded_per_match' => round((int) $c->conceded / (int) $c->played, 2),
            ])->sortBy(fn (array $r) => [$r['conceded_per_match'], -$r['played'], $r['club']['name']])->take(self::TOP)->values()->all(),
        ];
    }

    // ------------------------------------------------------------------ record e forma dei club (letture di ZoneQueries)

    /** Il record di un club (bilancio, gol, vittoria più larga, striscia). */
    public function clubRecord(int $clubId): array
    {
        return $this->queries->clubRecord($clubId);
    }

    /** La forma di un club in un torneo: gli ultimi `n` risultati W/D/L, dal più vecchio al più recente. */
    public function form(int $tid, int $clubId, int $n = 5): array
    {
        return $this->queries->forms($tid, $n)[$clubId] ?? [];
    }
}
