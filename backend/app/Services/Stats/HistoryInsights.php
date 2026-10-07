<?php

namespace App\Services\Stats;

use App\Models\Game;
use App\Models\MatchPlayerStat;
use App\Models\Team;
use App\Support\Present;
use Illuminate\Support\Collection;

/**
 * Record, serie, rendimento e curiosità di un gruppo di partite della nostra squadra
 * (tutto lo storico, una stagione o una competizione): si calcola dalle partite giocate
 * e dalle distinte lette da XFive. Quello che non è nei dati resta fuori.
 */
final class HistoryInsights
{
    private const WEEKDAYS = [1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica'];

    private const MONTHS = [
        1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile', 5 => 'Maggio', 6 => 'Giugno',
        7 => 'Luglio', 8 => 'Agosto', 9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre',
    ];

    /** I mesi nell'ordine di una stagione sportiva: da settembre ad agosto. */
    private const SEASON_MONTHS = [9, 10, 11, 12, 1, 2, 3, 4, 5, 6, 7, 8];

    /** Un orario con meno partite di così non dice nulla. */
    private const MIN_SLOT_GAMES = 2;

    /** @return array{played:int,won:int,drawn:int,lost:int,goals_for:int,goals_against:int,points:int} */
    public static function record(Collection $games, int $ownId): array
    {
        $r = ['played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0, 'goals_for' => 0, 'goals_against' => 0, 'points' => 0];

        foreach ($games as $g) {
            [$for, $against] = self::score($g, $ownId);

            $r['played']++;
            $r['goals_for'] += $for;
            $r['goals_against'] += $against;

            if ($for > $against) {
                $r['won']++;
                $r['points'] += 3;
            } elseif ($for === $against) {
                $r['drawn']++;
                $r['points']++;
            } else {
                $r['lost']++;
            }
        }

        return $r;
    }

    /** Una partita in forma breve (serve a record, scontri diretti e liste). Richiede competition, home e away caricati. */
    public static function matchLine(Game $g, int $ownId): array
    {
        return [
            'match_id' => $g->id,
            'season' => $g->competition->season,
            'competition_name' => $g->competition->name,
            'kickoff_at' => $g->kickoff_at?->toIso8601String(),
            'home_team' => $g->home->name,
            'away_team' => $g->away->name,
            'home_score' => $g->home_score,
            'away_score' => $g->away_score,
            'result' => $g->resultFor($ownId),
        ];
    }

    /** @return array{0:int,1:int} reti fatte e subite dalla squadra $ownId */
    private static function score(Game $g, int $ownId): array
    {
        return $g->home_team_id === $ownId ? [(int) $g->home_score, (int) $g->away_score] : [(int) $g->away_score, (int) $g->home_score];
    }

    /**
     * @param  Collection<int, Game>  $games  partite giocate della nostra squadra, con competition, home e away
     * @param  bool  $withRoster  elenco dei giocatori con presenze e gol (su tutto lo storico non serve: c'è l'albo d'oro)
     * @return array<string, mixed>
     */
    public function forGames(Collection $games, Team $own, bool $withRoster = true): array
    {
        $ownId = $own->id;
        $games = $games->sortBy(fn (Game $g) => $g->kickoff_at?->getTimestamp() ?? PHP_INT_MAX)->values();
        $stats = $this->stats($games, $ownId);

        return [
            'records' => $this->records($games, $ownId, $stats),
            'streaks' => $this->streaks($games, $ownId),
            'splits' => $this->splits($games, $ownId),
            'referees' => $this->grouped($games, $ownId, fn (Game $g) => trim((string) $g->referee), $stats),
            'venues' => $this->grouped($games, $ownId, fn (Game $g) => trim((string) $g->venue)),
            'hat_tricks' => $this->hatTricks($stats, $games, $ownId),
            'player_records' => $this->playerRecords($stats, $games),
            'roster' => $withRoster ? $this->roster($stats) : [],
        ];
    }

    /**
     * Le righe di statistica dei nostri giocatori per queste partite (chi c'era, gol, cartellini, miglior giocatore).
     *
     * @return Collection<int, MatchPlayerStat>
     */
    private function stats(Collection $games, int $ownId): Collection
    {
        if ($games->isEmpty()) {
            return collect();
        }

        return MatchPlayerStat::query()
            ->whereIn('match_id', $games->pluck('id'))
            ->whereHas('player', fn ($p) => $p->where('team_id', $ownId))
            ->with('player')
            ->orderBy('id') // a pari merito vale l'ordine di inserimento, uguale su ogni database
            ->get();
    }

    /** @return array<string, mixed> */
    private function records(Collection $games, int $ownId, Collection $stats): array
    {
        $tally = self::record($games, $ownId);
        $ours = fn (Game $g) => self::score($g, $ownId);
        $best = fn (callable $by) => $games->isEmpty() ? null : $games->sortByDesc($by)->first();
        $line = fn (?Game $g) => $g ? self::matchLine($g, $ownId) : null;
        $per = fn (int $n) => $tally['played'] ? round($n / $tally['played'], 2) : 0.0;

        return [
            'goals_per_match' => $per($tally['goals_for']),
            'conceded_per_match' => $per($tally['goals_against']),
            'points_per_match' => $per($tally['points']),
            'clean_sheets' => $games->filter(fn (Game $g) => $ours($g)[1] === 0)->count(),
            'scoreless' => $games->filter(fn (Game $g) => $ours($g)[0] === 0)->count(),
            'best_win' => $line($games->filter(fn (Game $g) => $g->resultFor($ownId) === 'W')->sortByDesc(fn (Game $g) => [$ours($g)[0] - $ours($g)[1], $ours($g)[0]])->first()),
            'worst_defeat' => $line($games->filter(fn (Game $g) => $g->resultFor($ownId) === 'L')->sortByDesc(fn (Game $g) => [$ours($g)[1] - $ours($g)[0], $ours($g)[1]])->first()),
            'highest_scoring' => $line($best(fn (Game $g) => [(int) $g->home_score + (int) $g->away_score, abs((int) $g->home_score - (int) $g->away_score)])),
            'most_scored' => $line($best(fn (Game $g) => [$ours($g)[0], $ours($g)[0] - $ours($g)[1]])),
            'most_conceded' => $line($best(fn (Game $g) => [$ours($g)[1], $ours($g)[1] - $ours($g)[0]])),
            'hat_tricks_count' => $stats->filter(fn (MatchPlayerStat $s) => $s->goals >= 3)->count(),
            'doubles_count' => $stats->filter(fn (MatchPlayerStat $s) => $s->goals === 2)->count(),
            'yellow' => (int) $stats->sum('yellow'),
            'red' => (int) $stats->sum('red'),
        ];
    }

    /**
     * Le serie più lunghe, in ordine di data: vittorie, partite senza sconfitte, sconfitte, partite con gol, porta inviolata.
     *
     * @return array<string, array{length:int, from:?string, to:?string}>
     */
    private function streaks(Collection $games, int $ownId): array
    {
        $tests = [
            'wins' => fn (Game $g) => $g->resultFor($ownId) === 'W',
            'unbeaten' => fn (Game $g) => $g->resultFor($ownId) !== 'L',
            'losses' => fn (Game $g) => $g->resultFor($ownId) === 'L',
            'scoring' => fn (Game $g) => self::score($g, $ownId)[0] > 0,
            'clean_sheets' => fn (Game $g) => self::score($g, $ownId)[1] === 0,
        ];

        return array_map(fn (callable $holds) => $this->longestRun($games, $holds), $tests);
    }

    /** @return array{length:int, from:?string, to:?string} */
    private function longestRun(Collection $games, callable $holds): array
    {
        $best = ['length' => 0, 'from' => null, 'to' => null];
        $length = 0;
        $start = null;

        foreach ($games as $g) {
            if (! $holds($g)) {
                $length = 0;
                $start = null;

                continue;
            }
            $length++;
            $start ??= $g;
            if ($length > $best['length']) {
                $best = ['length' => $length, 'from' => $start->kickoff_at?->toDateString(), 'to' => $g->kickoff_at?->toDateString()];
            }
        }

        return $best;
    }

    /**
     * Il rendimento diviso per: prima o seconda nominata, formato, tipo di competizione, orario, giorno e mese.
     * Le partite senza data non entrano nelle divisioni per orario, giorno e mese.
     *
     * @return array<string, mixed>
     */
    private function splits(Collection $games, int $ownId): array
    {
        $scheduled = $games->filter(fn (Game $g) => $g->kickoff_at !== null);

        return [
            'home_away' => [
                'home' => self::record($games->filter(fn (Game $g) => $g->home_team_id === $ownId), $ownId),
                'away' => self::record($games->filter(fn (Game $g) => $g->away_team_id === $ownId), $ownId),
            ],
            'by_format' => $this->splitBy($games, $ownId, fn (Game $g) => (int) $g->competition->format, fn ($k) => "Calcio a {$k}", fn ($k, Collection $g) => -$g->count()),
            'by_kind' => $this->splitBy($games, $ownId, fn (Game $g) => (string) $g->competition->kind, fn ($k) => (string) $k, fn ($k, Collection $g) => -$g->count()),
            'by_slot' => $this->splitBy($scheduled, $ownId, fn (Game $g) => $g->kickoff_at->format('H:i'), fn ($k) => (string) $k, fn ($k) => $k, self::MIN_SLOT_GAMES),
            'by_weekday' => $this->splitBy($scheduled, $ownId, fn (Game $g) => $g->kickoff_at->dayOfWeekIso, fn ($k) => self::WEEKDAYS[$k], fn ($k) => $k),
            'by_month' => $this->splitBy($scheduled, $ownId, fn (Game $g) => $g->kickoff_at->month, fn ($k) => self::MONTHS[$k], fn ($k) => array_search($k, self::SEASON_MONTHS, true)),
        ];
    }

    /**
     * Raggruppa le partite e dà il bilancio di ogni gruppo.
     *
     * @param  callable(Game): (int|string)  $key
     * @param  callable(int|string): string  $label
     * @param  callable(int|string, Collection): (int|string)  $order
     * @return array<int, array<string, mixed>>
     */
    private function splitBy(Collection $games, int $ownId, callable $key, callable $label, callable $order, int $min = 1): array
    {
        return $games->groupBy($key)
            ->filter(fn (Collection $g) => $g->count() >= $min)
            ->map(fn (Collection $g, $k) => ['key' => (string) $k, 'label' => $label($k), 'sort' => $order($k, $g)] + self::record($g, $ownId))
            ->sortBy('sort')
            ->values()
            ->map(fn (array $row) => array_diff_key($row, ['sort' => 1]))
            ->all();
    }

    /**
     * Il bilancio diviso per un valore delle partite (arbitro, campo…): le partite senza valore non entrano.
     * Con le statistiche si aggiungono anche i cartellini dei nostri giocatori in quelle partite.
     *
     * @return array<int, array<string, mixed>>
     */
    private function grouped(Collection $games, int $ownId, callable $key, ?Collection $stats = null): array
    {
        $byMatch = $stats?->groupBy('match_id');

        return $games
            ->filter(fn (Game $g) => $key($g) !== '')
            ->groupBy($key)
            ->map(function (Collection $g, $name) use ($ownId, $byMatch) {
                $row = ['name' => (string) $name] + self::record($g, $ownId);
                if ($byMatch !== null) {
                    $ours = $g->flatMap(fn (Game $x) => $byMatch->get($x->id, collect()));
                    $row['yellow'] = (int) $ours->sum('yellow');
                    $row['red'] = (int) $ours->sum('red');
                }

                return $row;
            })
            ->sort(fn (array $a, array $b) => [$b['played'], $b['won'], $a['name']] <=> [$a['played'], $a['won'], $b['name']])
            ->values()
            ->all();
    }

    /**
     * Chi ha segnato tre o più reti in una partita.
     *
     * @return array<int, array{player: array<string, mixed>, goals: int, match: array<string, mixed>}>
     */
    private function hatTricks(Collection $stats, Collection $games, int $ownId): array
    {
        $byId = $games->keyBy('id');

        return $stats->filter(fn (MatchPlayerStat $s) => $s->goals >= 3 && $byId->has($s->match_id))
            ->map(fn (MatchPlayerStat $s) => [
                'player' => Present::publicPlayer($s->player),
                'goals' => (int) $s->goals,
                'match' => self::matchLine($byId[$s->match_id], $ownId),
            ])
            ->sort(fn (array $a, array $b) => [$b['goals'], $b['match']['kickoff_at']] <=> [$a['goals'], $a['match']['kickoff_at']])
            ->take(15)
            ->values()
            ->all();
    }

    /**
     * I primati individuali in una stagione: più gol, più presenze, più volte miglior giocatore.
     *
     * @return array<string, array{player: array<string, mixed>, season: string, value: int}|null>
     */
    private function playerRecords(Collection $stats, Collection $games): array
    {
        $season = $games->mapWithKeys(fn (Game $g) => [$g->id => $g->competition->season]);
        $perSeason = $stats->filter(fn (MatchPlayerStat $s) => $season->has($s->match_id))
            ->groupBy(fn (MatchPlayerStat $s) => $s->player_id.'|'.$season[$s->match_id])
            ->map(fn (Collection $rows) => [
                'player' => $rows->first()->player,
                'season' => $season[$rows->first()->match_id],
                'goals' => (int) $rows->sum('goals'),
                'apps' => $rows->where('played', true)->count(),
                'mvp' => $rows->where('is_mvp', true)->count(),
            ]);

        $top = function (string $field) use ($perSeason): ?array {
            $row = $perSeason->sortByDesc(fn (array $r) => [$r[$field], $r['apps']])->first();

            return $row && $row[$field] > 0
                ? ['player' => Present::publicPlayer($row['player']), 'season' => $row['season'], 'value' => $row[$field]]
                : null;
        };

        return ['goals' => $top('goals'), 'apps' => $top('apps'), 'mvp' => $top('mvp')];
    }

    /**
     * Tutti i giocatori che hanno giocato, con presenze, gol, cartellini e volte miglior giocatore.
     *
     * @return array<int, array<string, mixed>>
     */
    private function roster(Collection $stats): array
    {
        return $stats->groupBy('player_id')
            ->map(function (Collection $rows) {
                $apps = $rows->where('played', true)->count();
                $goals = (int) $rows->sum('goals');

                return [
                    'player' => Present::publicPlayer($rows->first()->player),
                    'apps' => $apps,
                    'goals' => $goals,
                    'mvp' => $rows->where('is_mvp', true)->count(),
                    'yellow' => (int) $rows->sum('yellow'),
                    'red' => (int) $rows->sum('red'),
                    'goals_per_match' => $apps ? round($goals / $apps, 2) : 0.0,
                ];
            })
            ->filter(fn (array $r) => $r['apps'] > 0 || $r['goals'] > 0)
            ->sort(fn (array $a, array $b) => [$b['apps'], $b['goals'], $a['player']['full_name']] <=> [$a['apps'], $a['goals'], $b['player']['full_name']])
            ->values()
            ->all();
    }
}
