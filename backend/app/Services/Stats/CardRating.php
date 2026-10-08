<?php

namespace App\Services\Stats;

/**
 * Il voto della figurina, da 80 a 99, con pesi diversi per ruolo. Cinque fasce di quattro punti:
 * bronzo 80-83, argento 84-87, oro 88-91, platino 92-95, fuoco 96-99.
 *
 * Ogni voce dà un punteggio da 0 a 1 e vale una quota del voto (i pesi di un ruolo fanno 100%):
 *   conceded      gol subiti a partita con lui in campo, confrontati con la media della squadra (meno è meglio)
 *   clean_sheets  quota di partite senza subire gol
 *   goals         gol fatti a partita
 *   wins          quota di vittorie con lui in campo
 *   presence      presenze (60 = pieno)
 *   mvp           volte miglior giocatore (5 = pieno)
 * I «pieni» sono tarati su un campionato amatoriale: 0,7 gol a partita, 70% di vittorie, porta inviolata nel 40% delle partite.
 * I cartellini tolgono fino al 5%. Le medie si «correggono» verso un valore basso (come se ci fossero 12 partite in
 * più) e chi ha meno di 10 partite ha il voto scalato in proporzione: una grande partita non fa una leggenda.
 * La scomposizione (parts, penalty) è quella che il sito mostra premendo «i».
 */
final class CardRating
{
    public const MIN = 80;

    public const MAX = 99;

    private const SPAN = self::MAX - self::MIN;

    /** Partite «fittizie» aggiunte a ogni media. */
    private const K = 12;

    /** Partite che servono per il voto pieno. */
    private const FULL_SAMPLE = 10;

    /** @var array<string, array<string, float>> peso di ogni voce per ruolo */
    private const WEIGHTS = [
        'portiere' => ['conceded' => 0.40, 'clean_sheets' => 0.20, 'goals' => 0.00, 'wins' => 0.15, 'presence' => 0.15, 'mvp' => 0.10],
        'difensore' => ['conceded' => 0.30, 'clean_sheets' => 0.10, 'goals' => 0.10, 'wins' => 0.20, 'presence' => 0.15, 'mvp' => 0.15],
        'centrocampista' => ['conceded' => 0.10, 'clean_sheets' => 0.00, 'goals' => 0.30, 'wins' => 0.25, 'presence' => 0.15, 'mvp' => 0.20],
        'attaccante' => ['conceded' => 0.00, 'clean_sheets' => 0.00, 'goals' => 0.55, 'wins' => 0.20, 'presence' => 0.15, 'mvp' => 0.10],
    ];

    private const LABELS = [
        'conceded' => 'Gol subiti a partita',
        'clean_sheets' => 'Porta inviolata',
        'goals' => 'Gol a partita',
        'wins' => 'Vittorie',
        'presence' => 'Presenze',
        'mvp' => 'Miglior giocatore',
    ];

    public static function tierOf(int $ovr): string
    {
        return match (true) {
            $ovr >= 96 => 'fuoco',
            $ovr >= 92 => 'platino',
            $ovr >= 88 => 'oro',
            $ovr >= 84 => 'argento',
            default => 'bronzo',
        };
    }

    /**
     * @param  array<string, int|float>  $t  i totali della scheda: matches, goals, wins, mvp, yellow, red, conceded, clean_sheets, team_conceded_per_match
     * @return array{ovr:int, tier:string, role:string, matches:int, confidence:float, parts:array<int, array<string, mixed>>, penalty:array<string, mixed>}
     */
    public function rate(?string $role, array $t): array
    {
        $role = isset(self::WEIGHTS[(string) $role]) ? (string) $role : 'centrocampista';
        $m = max(0, (int) ($t['matches'] ?? 0));
        $k = self::K;
        $confidence = min(1.0, $m / self::FULL_SAMPLE);

        $teamConceded = (float) ($t['team_conceded_per_match'] ?? 0) > 0 ? (float) $t['team_conceded_per_match'] : 1.5;

        // le medie «corrette»: verso un valore basso, così poche partite non bastano a brillare
        $conceded = (((int) ($t['conceded'] ?? 0)) + 1.4 * $teamConceded * $k) / ($m + $k);
        $cleanShare = (((int) ($t['clean_sheets'] ?? 0)) + 0.10 * $k) / ($m + $k);
        $gpm = (((int) ($t['goals'] ?? 0)) + 0.15 * $k) / ($m + $k);
        $winShare = (((int) ($t['wins'] ?? 0)) + 0.25 * $k) / ($m + $k);

        $scores = [
            'conceded' => ['score' => self::clamp(1.4 - 0.9 * ($conceded / $teamConceded)), 'value' => round($conceded, 2), 'reference' => round($teamConceded, 2), 'unit' => 'per_match'],
            'clean_sheets' => ['score' => self::clamp($cleanShare / 0.4), 'value' => round($cleanShare, 3), 'reference' => 0.4, 'unit' => 'share'],
            'goals' => ['score' => self::clamp($gpm / 0.7), 'value' => round($gpm, 2), 'reference' => 0.7, 'unit' => 'per_match'],
            'wins' => ['score' => self::clamp(($winShare - 0.2) / 0.5), 'value' => round($winShare, 3), 'reference' => 0.7, 'unit' => 'share'],
            'presence' => ['score' => self::clamp($m / 60), 'value' => $m, 'reference' => 60, 'unit' => 'count'],
            'mvp' => ['score' => self::clamp(((int) ($t['mvp'] ?? 0)) / 5), 'value' => (int) ($t['mvp'] ?? 0), 'reference' => 5, 'unit' => 'count'],
        ];

        $parts = [];
        $total = 0.0;
        foreach (self::WEIGHTS[$role] as $key => $weight) {
            if ($weight <= 0) {
                continue; // una voce che non pesa non si mostra
            }
            $points = $scores[$key]['score'] * $weight * self::SPAN * $confidence;
            $total += $points;
            $parts[] = ['key' => $key, 'label' => self::LABELS[$key], 'weight' => $weight, 'points' => round($points, 2)] + $scores[$key];
        }

        $yellow = (int) ($t['yellow'] ?? 0);
        $red = (int) ($t['red'] ?? 0);
        $penaltyShare = min($red * 3 + $yellow * 0.3, 6) / 6 * 0.05;
        $penaltyPoints = $penaltyShare * self::SPAN * $confidence;

        $ovr = (int) round(self::clamp(($total - $penaltyPoints) / self::SPAN) * self::SPAN) + self::MIN;

        return [
            'ovr' => $ovr,
            'tier' => self::tierOf($ovr),
            'role' => $role,
            'matches' => $m,
            'confidence' => round($confidence, 2),
            'parts' => $parts,
            'penalty' => ['yellow' => $yellow, 'red' => $red, 'max_weight' => 0.05, 'points' => round($penaltyPoints, 2)],
        ];
    }

    private static function clamp(float $n): float
    {
        return min(1.0, max(0.0, $n));
    }
}
