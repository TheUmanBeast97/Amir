<?php

namespace App\Services\Stats;

use App\Models\Competition;
use App\Models\Game;
use App\Models\MatchPlayerStat;
use App\Models\Team;
use App\Support\Present;
use Illuminate\Support\Collection;

/** Storico delle stagioni passate: record, posizioni finali, scontri diretti. */
final class HistoryService
{
    private const KIND_ORDER = ['campionato' => 0, 'coppa_lega' => 1, 'coppa_categoria' => 2, 'coppa' => 3, 'torneo' => 4];

    /**
     * Solo i campionati hanno una "posizione finale" significativa: nelle coppe
     * (gironi + tabellone) la classifica calcolata dai punti non dice chi ha vinto.
     */
    private const RANKED_KINDS = ['campionato'];

    public function __construct(private readonly StandingsCalculator $standings, private readonly HistoryInsights $insights = new HistoryInsights) {}

    /** @return array{summary: array<string,mixed>, seasons: array<int, array<string,mixed>>} */
    public function overview(Team $own): array
    {
        $competitions = Competition::counted()
            ->where('has_own_team', true)
            ->where('is_current', false)
            ->orderByDesc('season')
            ->get()
            ->sortBy(fn (Competition $c) => [$c->season === null ? '' : $c->season, self::KIND_ORDER[$c->kind] ?? 9, $c->name])
            ->sortByDesc('season')
            ->values();

        $seasons = [];
        $allGames = collect();

        foreach ($competitions as $competition) {
            $games = $this->ownPlayedGames($competition, $own);
            if ($games->isEmpty()) {
                continue;
            }

            $allGames = $allGames->merge($games);
            $rows = $this->standings->forCompetition($competition);
            $position = null;

            if (in_array($competition->kind, self::RANKED_KINDS, true)) {
                $position = collect($rows)->first(fn (array $r) => $r['team']->id === $own->id)['position'] ?? null;
            }

            $seasons[$competition->season]['competitions'][] = [
                'id' => $competition->id,
                'name' => $competition->name,
                'kind' => $competition->kind,
                'format' => (int) $competition->format,
                'season' => $competition->season,
                'final_position' => $position,
                'teams_count' => in_array($competition->kind, self::RANKED_KINDS, true) ? count($rows) : null,
                'record' => $this->record($games, $own->id),
            ];
            $seasons[$competition->season]['games'] = ($seasons[$competition->season]['games'] ?? collect())->merge($games);
        }

        $scorers = $this->topScorersBySeason($own);

        $out = [];
        foreach ($seasons as $season => $data) {
            $out[] = [
                'season' => $season,
                'record' => $this->record($data['games'], $own->id),
                'competitions' => $data['competitions'],
                'top_scorers' => $scorers[$season] ?? [],
                'insights' => $this->insights->forGames($data['games'], $own),
            ];
        }
        usort($out, fn (array $a, array $b) => strcmp($b['season'], $a['season']));

        return [
            'summary' => $this->summary($allGames, $own, count($out)),
            'seasons' => $out,
            'opponents' => $this->opponents($allGames, $own),
            'insights' => $this->insights->forGames($allGames, $own, withRoster: false),
        ];
    }

    /**
     * Bilancio contro ogni avversaria incontrata (solo competizioni che contano), le più frequenti per prime.
     *
     * @return array<int, array<string, mixed>>
     */
    private function opponents(Collection $games, Team $own): array
    {
        return $games
            ->groupBy(fn (Game $g) => $g->home_team_id === $own->id ? $g->away_team_id : $g->home_team_id)
            ->map(function (Collection $g) use ($own) {
                $first = $g->first();
                $team = $first->home_team_id === $own->id ? $first->away : $first->home;

                return ['team' => Present::team($team)] + $this->record($g, $own->id);
            })
            ->sort(fn (array $a, array $b) => [$b['played'], $b['won'], $a['team']['name']] <=> [$a['played'], $a['won'], $b['team']['name']])
            ->values()
            ->all();
    }

    /**
     * I tre migliori marcatori di ogni stagione, dalle distinte lette (le stagioni senza distinte restano vuote).
     *
     * @return array<string, array<int, array{player: array<string, mixed>, goals: int}>>
     */
    private function topScorersBySeason(Team $own): array
    {
        $rows = MatchPlayerStat::query()
            ->where('goals', '>', 0)
            ->whereHas('player', fn ($p) => $p->where('team_id', $own->id))
            ->whereHas('game', fn ($g) => $g->where('status', Game::PLAYED)->whereHas('competition', fn ($c) => $c->counted()))
            ->with(['player', 'game.competition:id,season'])
            ->get();

        return $rows
            ->groupBy(fn (MatchPlayerStat $s) => $s->game->competition->season)
            ->map(fn (Collection $season) => $season->groupBy('player_id')
                ->map(fn (Collection $g) => ['player' => Present::publicPlayer($g->first()->player), 'goals' => (int) $g->sum('goals')])
                ->sortByDesc('goals')
                ->take(3)
                ->values()
                ->all())
            ->all();
    }

    /** @return array<string, mixed> */
    public function competitionDetail(Competition $competition, Team $own): array
    {
        $games = Game::with(['competition', 'home', 'away', 'event'])
            ->where('competition_id', $competition->id)
            ->where('status', '!=', Game::CANCELLED)
            ->orderByRaw('kickoff_at IS NULL')
            ->orderBy('kickoff_at')
            ->orderBy('round')
            ->get();

        $rows = $this->standings->forCompetition($competition);

        return [
            'competition' => Present::competition($competition),
            'standings' => array_map(fn (array $r) => Present::standingRow($r), $rows),
            'own_matches' => $games->filter(fn (Game $g) => $this->involves($g, $own->id))
                ->map(fn (Game $g) => Present::match($g, $own->id))->values()->all(),
            'all_matches' => $games->map(fn (Game $g) => Present::match($g, $own->id))->values()->all(),
            'awards' => $this->awards($competition, $rows, $own),
            'insights' => $this->insights->forGames($this->ownPlayedGames($competition, $own), $own),
        ];
    }

    /**
     * Primo in classifica, miglior attacco e miglior difesa: solo nei campionati (nelle coppe a gironi
     * e tabellone la classifica a punti non dice chi ha vinto) e solo se qualcuno ha giocato.
     *
     * @param  array<int, array<string, mixed>>  $rows  classifica calcolata, con "team" = modello Team
     * @return array<string, mixed>|null
     */
    private function awards(Competition $competition, array $rows, Team $own): ?array
    {
        $played = collect($rows)->filter(fn (array $r) => $r['played'] > 0)->values();
        if (! in_array($competition->kind, self::RANKED_KINDS, true) || $played->count() < 2) {
            return null;
        }

        $pick = fn (array $r, string $field) => ['team' => Present::team($r['team']), 'value' => (int) $r[$field], 'played' => (int) $r['played']];
        // si confrontano le medie a partita: chi ha giocato meno partite non è avvantaggiato
        $attack = $played->sortByDesc(fn (array $r) => [$r['goals_for'] / $r['played'], $r['goals_for']])->first();
        $defence = $played->sortBy(fn (array $r) => [$r['goals_against'] / $r['played'], -$r['played']])->first();
        $ours = $played->first(fn (array $r) => $r['team']->id === $own->id);

        return [
            'first_place' => $pick($played->first(), 'points'),
            'best_attack' => $pick($attack, 'goals_for'),
            'best_defence' => $pick($defence, 'goals_against'),
            'own_position' => $ours['position'] ?? null,
            'teams_count' => $played->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function headToHead(Team $own, Team $opponent): array
    {
        $games = Game::with(['competition', 'home', 'away'])
            ->where('status', Game::PLAYED)
            ->whereHas('competition', fn ($c) => $c->counted())
            ->where(fn ($q) => $q
                ->where(fn ($x) => $x->where('home_team_id', $own->id)->where('away_team_id', $opponent->id))
                ->orWhere(fn ($x) => $x->where('home_team_id', $opponent->id)->where('away_team_id', $own->id)))
            ->orderByRaw('kickoff_at IS NULL')
            ->orderByDesc('kickoff_at')
            ->get();

        $record = $this->record($games, $own->id);

        return [
            'opponent' => Present::team($opponent),
            'played' => $record['played'],
            'won' => $record['won'],
            'drawn' => $record['drawn'],
            'lost' => $record['lost'],
            'goals_for' => $record['goals_for'],
            'goals_against' => $record['goals_against'],
            'matches' => $games->map(fn (Game $g) => $this->headToHeadMatch($g, $own->id))->values()->all(),
        ];
    }

    /** @return Collection<int, Game> */
    private function ownPlayedGames(Competition $competition, Team $own): Collection
    {
        return Game::with(['competition', 'home', 'away'])
            ->where('competition_id', $competition->id)
            ->involving($own->id)
            ->where('status', Game::PLAYED)
            ->get();
    }

    /** @return array{played:int,won:int,drawn:int,lost:int,goals_for:int,goals_against:int,points:int} */
    private function record(Collection $games, int $ownId): array
    {
        return HistoryInsights::record($games, $ownId);
    }

    /** @return array<string, mixed> */
    private function summary(Collection $games, Team $own, int $seasonsCount): array
    {
        $diff = fn (Game $g) => ($g->home_team_id === $own->id ? 1 : -1) * ($g->home_score - $g->away_score);

        $best = $games->filter(fn (Game $g) => $g->resultFor($own->id) === 'W')
            ->sortByDesc(fn (Game $g) => [$diff($g), max($g->home_score, $g->away_score)])->first();
        $worst = $games->filter(fn (Game $g) => $g->resultFor($own->id) === 'L')
            ->sortBy(fn (Game $g) => [$diff($g), -max($g->home_score, $g->away_score)])->first();

        $opponents = $games->groupBy(fn (Game $g) => $g->home_team_id === $own->id ? $g->away_team_id : $g->home_team_id)
            ->map->count()->sortDesc();
        $topId = $opponents->keys()->first();
        $top = $topId ? Team::find($topId) : null;

        return [
            'record' => $this->record($games, $own->id),
            'seasons_count' => $seasonsCount,
            'best_win' => $best ? $this->headToHeadMatch($best, $own->id) : null,
            'worst_defeat' => $worst ? $this->headToHeadMatch($worst, $own->id) : null,
            'most_frequent_opponent' => $top ? ['team' => Present::team($top), 'played' => (int) $opponents->first()] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function headToHeadMatch(Game $g, int $ownId): array
    {
        return HistoryInsights::matchLine($g, $ownId);
    }

    private function involves(Game $g, int $teamId): bool
    {
        return $g->home_team_id === $teamId || $g->away_team_id === $teamId;
    }
}
