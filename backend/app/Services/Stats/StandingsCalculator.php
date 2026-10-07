<?php

namespace App\Services\Stats;

use App\Models\Competition;
use App\Models\Game;
use App\Models\Team;

/**
 * Classifica calcolata dai risultati: 3 punti per vittoria, 1 per pareggio.
 * Ordine: punti, differenza reti, gol fatti, nome (nessun criterio di
 * spareggio per scontri diretti: XFive non lo espone nelle pagine pubbliche).
 */
final class StandingsCalculator
{
    /** @return array<int, array<string, mixed>> con la chiave "team" = modello Team */
    public function forCompetition(Competition $competition): array
    {
        $games = Game::with(['home', 'away'])
            ->where('competition_id', $competition->id)
            ->where('status', '!=', Game::CANCELLED)
            ->get();

        $rows = [];

        foreach ($games as $game) {
            $rows[$game->home_team_id] ??= $this->emptyRow($game->home);
            $rows[$game->away_team_id] ??= $this->emptyRow($game->away);

            if (! $game->isPlayed()) {
                continue;
            }

            $this->apply($rows[$game->home_team_id], $game->home_score, $game->away_score);
            $this->apply($rows[$game->away_team_id], $game->away_score, $game->home_score);
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b) => [$b['points'], $b['goal_diff'], $b['goals_for'], $a['team']->name]
            <=> [$a['points'], $a['goal_diff'], $a['goals_for'], $b['team']->name]);

        // Pari merito: chi ha stessi punti, differenza reti e gol fatti condivide la
        // posizione (1, 1, 1, 4...). Così, a stagione non iniziata, nessuno risulta "primo".
        foreach ($rows as $i => &$row) {
            $prev = $rows[$i - 1] ?? null;
            $tied = $prev !== null
                && $prev['points'] === $row['points']
                && $prev['goal_diff'] === $row['goal_diff']
                && $prev['goals_for'] === $row['goals_for'];

            $row['position'] = $tied ? $prev['position'] : $i + 1;
        }
        unset($row);

        // "position" in testa, come nel contratto
        return array_map(fn (array $r) => ['position' => $r['position']] + $r, $rows);
    }

    /** @return array<string, mixed> */
    private function emptyRow(Team $team): array
    {
        return [
            'position' => 0,
            'team' => $team,
            'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0,
            'goals_for' => 0, 'goals_against' => 0, 'goal_diff' => 0,
            'points' => 0,
        ];
    }

    private function apply(array &$row, int $for, int $against): void
    {
        $row['played']++;
        $row['goals_for'] += $for;
        $row['goals_against'] += $against;
        $row['goal_diff'] = $row['goals_for'] - $row['goals_against'];

        if ($for > $against) {
            $row['won']++;
            $row['points'] += 3;
        } elseif ($for === $against) {
            $row['drawn']++;
            $row['points'] += 1;
        } else {
            $row['lost']++;
        }
    }
}
