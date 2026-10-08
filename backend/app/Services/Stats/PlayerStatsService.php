<?php

namespace App\Services\Stats;

use App\Models\Competition;
use App\Models\Game;
use App\Models\MatchPlayerStat;
use App\Models\Player;
use App\Models\PlayerCompetitionStat;
use App\Models\Team;
use App\Support\Present;
use Illuminate\Support\Collection;

/**
 * Pagina personale di un giocatore: numeri complessivi, stagione per stagione,
 * forma recente, impatto sulla squadra e record. Tutto ricavato dalle partite
 * giocate (presenza = in distinta); le competizioni escluse e le amichevoli non contano.
 */
final class PlayerStatsService
{
    /** @return array<string, mixed> */
    public function profile(Player $player, Team $own): array
    {
        $rows = $this->appearances($player, $own);
        $log = $rows->map(fn (array $r) => $r['log'])->values();

        $official = PlayerCompetitionStat::with('competition')
            ->where('player_id', $player->id)
            ->whereHas('competition', fn ($c) => $c->counted())
            ->get();
        $career = $this->career($rows, $official);
        $totals = $this->withOfficial($this->totals($rows), $career) + ['team_conceded_per_match' => $this->teamConcededPerMatch($own)];

        return [
            'player' => $this->playerBlock($player, $rows),
            'totals' => $totals + [
                'competitions' => count($career),
                'mvp_points' => (int) $official->sum('mvp_points'),
            ],
            'card' => (new CardRating)->rate($player->role, $totals),
            'rank' => $this->rank($player, $own),
            'by_season' => $this->bySeason($rows, $career),
            'by_kind' => $this->byKind($rows),
            'career' => $career,
            'form' => $log->take(-10)->values()->all(),
            'match_log' => $log->reverse()->values()->all(),
            'impact' => $this->impact($player, $own, $rows),
            'records' => $this->records($rows),
            'partners' => $this->partners($player, $rows),
            'victims' => $this->victims($rows),
            'milestones' => $this->milestones($totals),
            'streaks' => $this->currentStreaks($rows),
            'scout' => $this->scout($player),
        ];
    }

    /** Con meno partite insieme una media non dice niente. */
    private const MIN_SHARED_MATCHES = 6;

    /** Le tappe che contano: "mancano 2 gol ai 50". */
    private const MILESTONES = [10, 25, 50, 75, 100, 150, 200, 300];

    /**
     * Classifica di sempre: tutti i giocatori (anche gli ex) con presenze e gol.
     *
     * @return array<int, array<string, mixed>>
     */
    public function leaderboard(Team $own): array
    {
        $totals = $this->allTotals($own);

        return Player::where('team_id', $own->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (Player $p) => isset($totals[$p->id]))
            ->map(function (Player $p) use ($totals) {
                $t = $totals[$p->id];

                return [
                    'player' => Present::publicPlayer($p) + ['is_active' => (bool) $p->is_active],
                    'matches' => $t['matches'],
                    'goals' => $t['goals'],
                    'yellow' => $t['yellow'],
                    'red' => $t['red'],
                    'mvp' => $t['mvp'],
                    'goals_per_match' => $t['matches'] > 0 ? round($t['goals'] / $t['matches'], 2) : 0.0,
                    'seasons' => $t['seasons'],
                ];
            })
            ->sortBy([['matches', 'desc'], ['goals', 'desc']])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>> una riga per presenza, dalla più vecchia
     */
    private function appearances(Player $player, Team $own): Collection
    {
        return MatchPlayerStat::query()
            ->where('player_id', $player->id)
            ->where('played', true)
            ->whereHas('game', fn ($g) => $g
                ->where('status', Game::PLAYED)
                ->whereHas('competition', fn ($c) => $c->counted()))
            ->with(['game.competition', 'game.home', 'game.away'])
            ->orderBy('id')
            ->get()
            ->sortBy(fn (MatchPlayerStat $s) => ($s->game->kickoff_at?->timestamp ?? 0).sprintf('%08d', $s->match_id))
            ->values()
            ->map(function (MatchPlayerStat $s) use ($own) {
                $g = $s->game;
                $isHome = $g->home_team_id === $own->id;
                $opponent = $isHome ? $g->away : $g->home;
                $for = $isHome ? $g->home_score : $g->away_score;
                $against = $isHome ? $g->away_score : $g->home_score;

                return [
                    'match_id' => $g->id,
                    'competition_id' => $g->competition_id,
                    'competition' => $g->competition,
                    'season' => $g->competition->season,
                    'kind' => $g->competition->kind,
                    'result' => $g->resultFor($own->id),
                    'goals' => (int) $s->goals,
                    'yellow' => (int) $s->yellow,
                    'red' => (int) $s->red,
                    'mvp' => (bool) $s->is_mvp,
                    'kickoff' => $g->kickoff_at,
                    'for' => (int) $for,
                    'against' => (int) $against,
                    'log' => [
                        'match_id' => $g->id,
                        'date' => $g->kickoff_at?->toDateString(),
                        'season' => $g->competition->season,
                        'competition_name' => $g->competition->name,
                        'kind' => $g->competition->kind,
                        'round_label' => $g->round_label,
                        'opponent' => Present::team($opponent),
                        'home_away' => $isHome ? 'H' : 'A',
                        'score_for' => (int) $for,
                        'score_against' => (int) $against,
                        'result' => $g->resultFor($own->id),
                        'goals' => (int) $s->goals,
                        'yellow' => (int) $s->yellow,
                        'red' => (int) $s->red,
                        'mvp' => (bool) $s->is_mvp,
                    ],
                ];
            });
    }

    /** @return array<string, mixed> */
    private function playerBlock(Player $player, Collection $rows): array
    {
        $seasons = $rows->pluck('season')->unique()->sort()->values();

        return Present::publicPlayer($player) + [
            'is_active' => (bool) $player->is_active,
            'age' => $player->birth_date?->age ?? ($player->xfive_profile['age'] ?? null),
            'first_season' => $seasons->first(),
            'last_season' => $seasons->last(),
            'seasons_count' => $seasons->count(),
        ];
    }

    /** @return array<string, int|float> */
    private function totals(Collection $rows): array
    {
        $matches = $rows->count();
        $wins = $rows->where('result', 'W')->count();
        $draws = $rows->where('result', 'D')->count();
        $goals = (int) $rows->sum('goals');
        $conceded = (int) $rows->sum('against');

        return [
            'matches' => $matches,
            'goals' => $goals,
            'goals_per_match' => $matches > 0 ? round($goals / $matches, 2) : 0.0,
            // la squadra con lui in campo: gol subiti e porte inviolate (contano per portieri e difensori)
            'conceded' => $conceded,
            'conceded_per_match' => $matches > 0 ? round($conceded / $matches, 2) : 0.0,
            'clean_sheets' => $rows->where('against', 0)->count(),
            'yellow' => (int) $rows->sum('yellow'),
            'red' => (int) $rows->sum('red'),
            'mvp' => (int) $rows->where('mvp', true)->count(),
            'wins' => $wins,
            'draws' => $draws,
            'losses' => $matches - $wins - $draws,
            'win_rate' => $matches > 0 ? round($wins / $matches, 3) : 0.0,
            'points_per_match' => $matches > 0 ? round(($wins * 3 + $draws) / $matches, 2) : 0.0,
        ];
    }

    /** Gol subiti a partita dalla squadra in tutte le partite contate: il termine di confronto per il voto di chi difende. */
    private function teamConcededPerMatch(Team $own): float
    {
        $games = Game::query()
            ->involving($own->id)
            ->where('status', Game::PLAYED)
            ->whereHas('competition', fn ($c) => $c->counted())
            ->get(['home_team_id', 'away_team_id', 'home_score', 'away_score']);

        if ($games->isEmpty()) {
            return 0.0;
        }

        $against = $games->sum(fn (Game $g) => (int) ($g->home_team_id === $own->id ? $g->away_score : $g->home_score));

        return round($against / $games->count(), 2);
    }

    /** @return array{appearances:?int, goals:?int, of:int} */
    private function rank(Player $player, Team $own): array
    {
        $all = $this->allTotals($own);
        $mine = $all[$player->id] ?? null;
        if ($mine === null) {
            return ['appearances' => null, 'goals' => null, 'of' => count($all)];
        }

        $above = fn (string $key) => 1 + collect($all)->filter(fn (array $t) => $t[$key] > $mine[$key])->count();

        return [
            'appearances' => $above('matches'),
            'goals' => $mine['goals'] > 0 ? $above('goals') : null,
            'of' => count($all),
        ];
    }

    /** @return array<int, array{matches:int,goals:int,yellow:int,red:int,mvp:int,seasons:int}> per player_id */
    private function allTotals(Team $own): array
    {
        $out = [];
        $official = PlayerCompetitionStat::with('competition:id,season')
            ->whereHas('competition', fn ($c) => $c->counted())
            ->get()
            ->groupBy('player_id');

        $appearances = MatchPlayerStat::query()
            ->where('played', true)
            ->whereHas('player', fn ($p) => $p->where('team_id', $own->id))
            ->whereHas('game', fn ($g) => $g
                ->where('status', Game::PLAYED)
                ->whereHas('competition', fn ($c) => $c->counted()))
            ->with('game.competition:id,season')
            ->orderBy('id')
            ->get()
            ->groupBy('player_id');

        // anche chi ha solo le statistiche ufficiali di XFive (nessuna distinta letta) entra in classifica
        foreach ($appearances->keys()->merge($official->keys())->unique() as $pid) {
            $stats = $appearances->get($pid, collect());

            // stessa regola della tabella carriera: per ogni torneo vale il valore più alto
            $byComp = $stats->groupBy(fn ($s) => $s->game->competition_id);
            $off = ($official->get($pid) ?? collect())->keyBy('competition_id');
            $sum = ['goals' => 0, 'yellow' => 0, 'red' => 0];

            foreach ($byComp->keys()->merge($off->keys())->unique() as $cid) {
                $mine = $byComp->get($cid, collect());
                foreach ($sum as $field => $_) {
                    $sum[$field] += max((int) $mine->sum($field), (int) ($off->get($cid)?->{$field} ?? 0));
                }
            }

            $out[(int) $pid] = $sum + [
                'matches' => $stats->count(),
                'mvp' => $stats->where('is_mvp', true)->count(),
                'seasons' => $stats->map(fn ($s) => $s->game->competition->season)->merge($off->map(fn ($o) => $o->competition->season ?? null))->filter()->unique()->count(),
            ];
        }

        return $out;
    }

    /**
     * Gol e cartellini vengono dalla tabella carriera (per torneo: il valore più alto fra
     * le nostre distinte e le classifiche XFive), così totali e stagioni tornano sempre.
     *
     * @param  array<string, int|float>  $totals
     * @param  array<int, array<string, mixed>>  $career
     * @return array<string, int|float>
     */
    private function withOfficial(array $totals, array $career): array
    {
        $totals['goals'] = array_sum(array_column($career, 'goals'));
        $totals['yellow'] = array_sum(array_column($career, 'yellow'));
        $totals['red'] = array_sum(array_column($career, 'red'));
        $totals['goals_per_match'] = $totals['matches'] > 0 ? round($totals['goals'] / $totals['matches'], 2) : 0.0;

        return $totals;
    }

    /**
     * @param  array<int, array<string, mixed>>  $career
     * @return array<int, array<string, mixed>> dalla stagione più recente
     */
    private function bySeason(Collection $rows, array $career): array
    {
        $careerBySeason = collect($career)->groupBy(fn (array $c) => $c['competition']['season']);
        $seasons = $rows->pluck('season')->merge($careerBySeason->keys())->unique();

        return $seasons->map(function (string $season) use ($rows, $careerBySeason) {
            $t = $this->totals($rows->where('season', $season));
            $c = $careerBySeason->get($season, collect());

            return [
                'season' => $season,
                'matches' => $t['matches'],
                'goals' => (int) $c->sum('goals'),
                'yellow' => (int) $c->sum('yellow'),
                'red' => (int) $c->sum('red'),
                'mvp' => $t['mvp'],
                'wins' => $t['wins'],
                'draws' => $t['draws'],
                'losses' => $t['losses'],
            ];
        })->sortByDesc('season')->values()->all();
    }

    /** @return array<int, array{kind:string,matches:int,goals:int}> */
    private function byKind(Collection $rows): array
    {
        return $rows->groupBy('kind')
            ->map(fn (Collection $g, string $kind) => ['kind' => $kind, 'matches' => $g->count(), 'goals' => (int) $g->sum('goals')])
            ->sortByDesc('matches')
            ->values()
            ->all();
    }

    /**
     * Una riga per competizione. I gol "ufficiali" sono quelli delle classifiche di
     * XFive: possono essere più dei nostri quando XFive non ha pubblicato la distinta.
     *
     * @param  Collection<int, PlayerCompetitionStat>  $official
     * @return array<int, array<string, mixed>>
     */
    private function career(Collection $rows, Collection $official): array
    {
        $byComp = $rows->groupBy('competition_id');
        $officialBy = $official->keyBy('competition_id');

        $ids = $byComp->keys()->merge($officialBy->keys())->unique();
        $competitions = Competition::whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(function (int $id) use ($competitions, $byComp, $officialBy) {
            $c = $competitions[$id];
            $mine = $byComp->get($id, collect());
            $off = $officialBy->get($id);

            return [
                'competition' => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'season' => $c->season,
                    'kind' => $c->kind,
                    'format' => (int) $c->format,
                ],
                'matches' => $mine->count(),
                'goals' => max((int) $mine->sum('goals'), (int) ($off?->goals ?? 0)),
                'yellow' => max((int) $mine->sum('yellow'), (int) ($off?->yellow ?? 0)),
                'red' => max((int) $mine->sum('red'), (int) ($off?->red ?? 0)),
                'mvp' => $mine->where('mvp', true)->count(),
                'mvp_points' => (int) ($off?->mvp_points ?? 0),
            ];
        })
            ->sortByDesc(fn (array $r) => $r['competition']['season'].sprintf('%06d', $r['competition']['id']))
            ->values()
            ->all();
    }

    /**
     * Come va la squadra con e senza il giocatore, sulle partite con distinta tra il suo
     * esordio e la sua ultima presenza (o oggi, se è ancora in rosa).
     *
     * @return array<string, mixed>|null
     */
    private function impact(Player $player, Team $own, Collection $rows): ?array
    {
        if ($rows->count() < 3) {
            return null;
        }

        $from = $rows->first()['kickoff'];
        $to = $player->is_active ? now() : $rows->last()['kickoff'];
        $present = $rows->pluck('match_id')->all();

        $games = Game::with('competition')
            ->involving($own->id)
            ->where('status', Game::PLAYED)
            ->whereHas('competition', fn ($c) => $c->counted())
            ->whereHas('stats')
            ->whereBetween('kickoff_at', [$from, $to])
            ->get();

        [$with, $without] = $games->partition(fn (Game $g) => in_array($g->id, $present, true));

        return [
            'with' => $this->record($with, $own->id),
            'without' => $this->record($without, $own->id),
            'sample' => $games->count(),
        ];
    }

    /** @return array{played:int,won:int,drawn:int,lost:int,goals_for:int,goals_against:int,points_per_match:float} */
    private function record(Collection $games, int $ownId): array
    {
        $r = ['played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0, 'goals_for' => 0, 'goals_against' => 0];

        foreach ($games as $g) {
            $isHome = $g->home_team_id === $ownId;
            $for = $isHome ? $g->home_score : $g->away_score;
            $against = $isHome ? $g->away_score : $g->home_score;
            $r['played']++;
            $r['goals_for'] += $for;
            $r['goals_against'] += $against;
            $key = $for > $against ? 'won' : ($for === $against ? 'drawn' : 'lost');
            $r[$key]++;
        }

        $r['points_per_match'] = $r['played'] > 0 ? round(($r['won'] * 3 + $r['drawn']) / $r['played'], 2) : 0.0;

        return $r;
    }

    /**
     * I compagni con cui la squadra rende meglio quando giocano insieme (solo chi ha almeno 6 partite in comune):
     * i punti a partita della squadra nelle partite in cui c'erano entrambi.
     *
     * @return array<int, array<string, mixed>>
     */
    private function partners(Player $player, Collection $rows): array
    {
        if ($rows->count() < self::MIN_SHARED_MATCHES) {
            return [];
        }

        $result = $rows->pluck('result', 'match_id');

        return MatchPlayerStat::query()
            ->whereIn('match_id', $result->keys())
            ->where('player_id', '!=', $player->id)
            ->where('played', true)
            ->with('player')
            ->orderBy('id')
            ->get()
            ->groupBy('player_id')
            ->map(function (Collection $shared) use ($result) {
                $by = $shared->map(fn (MatchPlayerStat $s) => $result[$s->match_id])->countBy();
                $played = $shared->count();
                [$won, $drawn, $lost] = [$by['W'] ?? 0, $by['D'] ?? 0, $by['L'] ?? 0];

                return [
                    'player' => Present::publicPlayer($shared->first()->player),
                    'played' => $played,
                    'won' => $won,
                    'drawn' => $drawn,
                    'lost' => $lost,
                    'points_per_match' => round(($won * 3 + $drawn) / $played, 2),
                ];
            })
            ->filter(fn (array $m) => $m['played'] >= self::MIN_SHARED_MATCHES)
            ->sort(fn (array $a, array $b) => [$b['points_per_match'], $b['played']] <=> [$a['points_per_match'], $a['played']])
            ->take(4)
            ->values()
            ->all();
    }

    /**
     * Le squadre a cui ha segnato di più (le prime tre).
     *
     * @return array<int, array{team: array<string, mixed>, goals: int, matches: int}>
     */
    private function victims(Collection $rows): array
    {
        return $rows->groupBy(fn (array $r) => $r['log']['opponent']['id'])
            ->map(fn (Collection $g) => ['team' => $g->first()['log']['opponent'], 'goals' => (int) $g->sum('goals'), 'matches' => $g->count()])
            ->filter(fn (array $v) => $v['goals'] > 0)
            ->sort(fn (array $a, array $b) => [$b['goals'], $a['matches']] <=> [$a['goals'], $b['matches']])
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * La prossima tappa di presenze e gol (10, 25, 50, 75, 100…) e quanto manca; nulla se le ha superate tutte.
     *
     * @param  array<string, int|float>  $totals
     * @return array{matches: array<string, int>|null, goals: array<string, int>|null}
     */
    private function milestones(array $totals): array
    {
        $next = function (int $n): ?array {
            $target = collect(self::MILESTONES)->first(fn (int $m) => $m > $n);

            return $target ? ['current' => $n, 'next' => $target, 'missing' => $target - $n] : null;
        };

        return ['matches' => $next((int) $totals['matches']), 'goals' => $next((int) $totals['goals'])];
    }

    /**
     * Quante partite di fila a segno e quante di fila senza segnare, contando dall'ultima presenza.
     *
     * @return array{scoring_now: int, drought_now: int}
     */
    private function currentStreaks(Collection $rows): array
    {
        $scoring = 0;
        $drought = 0;
        foreach ($rows->reverse() as $r) {
            if ($r['goals'] > 0 && $drought === 0) {
                $scoring++;
            } elseif ($r['goals'] === 0 && $scoring === 0) {
                $drought++;
            } else {
                break;
            }
        }

        return ['scoring_now' => $scoring, 'drought_now' => $drought];
    }

    /** @return array{text: string, source: string, generated_at: string}|null */
    private function scout(Player $player): ?array
    {
        if (! $player->scout_text) {
            return null;
        }

        return [
            'text' => $player->scout_text,
            'source' => $player->scout_source ?? 'template',
            'generated_at' => $player->scout_generated_at?->toIso8601String() ?? '',
        ];
    }

    /** @return array<string, mixed> */
    private function records(Collection $rows): array
    {
        $best = $rows->sortByDesc('goals')->first();
        $firstGoal = $rows->first(fn (array $r) => $r['goals'] > 0);

        $streak = 0;
        $current = 0;
        foreach ($rows as $r) {
            $current = $r['goals'] > 0 ? $current + 1 : 0;
            $streak = max($streak, $current);
        }

        return [
            'debut' => $rows->first()['log'] ?? null,
            'first_goal' => $firstGoal['log'] ?? null,
            'most_goals_in_match' => ($best && $best['goals'] > 0) ? $best['log'] : null,
            'hat_tricks' => $rows->where('goals', '>=', 3)->count(),
            'scoring_streak' => $streak,
            'clean_appearances' => $rows->where('against', 0)->count(),
        ];
    }
}
