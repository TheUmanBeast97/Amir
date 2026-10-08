<?php

namespace Tests\Unit;

use App\Services\Stats\CardRating;
use PHPUnit\Framework\TestCase;

/** Il voto della figurina: da 75 a 99, con pesi diversi per ruolo e una scomposizione che si può spiegare. */
class CardRatingTest extends TestCase
{
    /** Totali di un giocatore come li produce la scheda; si passano solo le differenze. */
    private function totals(array $over = []): array
    {
        return $over + [
            'matches' => 0, 'goals' => 0, 'wins' => 0, 'win_rate' => 0.0, 'mvp' => 0, 'yellow' => 0, 'red' => 0,
            'conceded' => 0, 'clean_sheets' => 0, 'team_conceded_per_match' => 1.6,
        ];
    }

    private function rate(?string $role, array $over = []): array
    {
        return (new CardRating)->rate($role, $this->totals($over));
    }

    public function test_the_rating_always_stays_between_75_and_99_and_the_tier_follows_it(): void
    {
        $nobody = $this->rate('attaccante');
        $this->assertSame(75, $nobody['ovr']);
        $this->assertSame('bronzo', $nobody['tier']);

        $legend = $this->rate('attaccante', ['matches' => 120, 'goals' => 150, 'wins' => 100, 'win_rate' => 0.83, 'mvp' => 20]);
        $this->assertSame(99, $legend['ovr']);
        $this->assertSame('fuoco', $legend['tier']);

        $this->assertSame(['bronzo', 'argento', 'oro', 'platino', 'fuoco'], array_map(fn (int $o) => CardRating::tierOf($o), [79, 80, 89, 90, 95]));
    }

    public function test_a_goalkeeper_is_rated_on_goals_conceded_and_clean_sheets_not_on_goals_scored(): void
    {
        // stessi numeri di base; il portiere A subisce poco, il portiere B tanto
        $wall = $this->rate('portiere', ['matches' => 40, 'wins' => 24, 'win_rate' => 0.6, 'conceded' => 20, 'clean_sheets' => 16]);
        $sieve = $this->rate('portiere', ['matches' => 40, 'wins' => 24, 'win_rate' => 0.6, 'conceded' => 100, 'clean_sheets' => 1]);
        $this->assertGreaterThan($sieve['ovr'] + 6, $wall['ovr']);

        // i gol fatti non contano per un portiere
        $scoringWall = $this->rate('portiere', ['matches' => 40, 'wins' => 24, 'win_rate' => 0.6, 'conceded' => 20, 'clean_sheets' => 16, 'goals' => 30]);
        $this->assertSame($wall['ovr'], $scoringWall['ovr']);

        $keys = array_column($wall['parts'], 'key');
        $this->assertContains('conceded', $keys);
        $this->assertContains('clean_sheets', $keys);
        $this->assertNotContains('goals', $keys, 'una voce con peso zero non si mostra');
    }

    public function test_a_defender_weighs_goals_conceded_more_than_a_striker_does(): void
    {
        $base = ['matches' => 40, 'wins' => 20, 'win_rate' => 0.5, 'goals' => 8];

        $tightDef = $this->rate('difensore', $base + ['conceded' => 24]);
        $looseDef = $this->rate('difensore', $base + ['conceded' => 96]);
        $tightAtt = $this->rate('attaccante', $base + ['conceded' => 24]);
        $looseAtt = $this->rate('attaccante', $base + ['conceded' => 96]);

        $this->assertGreaterThan($looseDef['ovr'], $tightDef['ovr']);
        $this->assertSame($tightAtt['ovr'], $looseAtt['ovr'], 'per un attaccante i gol subiti non pesano');
    }

    public function test_a_striker_climbs_with_goals_per_match_and_a_midfielder_sits_in_between(): void
    {
        $base = ['matches' => 40, 'wins' => 20, 'win_rate' => 0.5, 'conceded' => 60];

        $att = fn (int $goals) => $this->rate('attaccante', $base + ['goals' => $goals])['ovr'];
        $this->assertGreaterThan($att(5), $att(40));

        $midGain = $this->rate('centrocampista', $base + ['goals' => 40])['ovr'] - $this->rate('centrocampista', $base + ['goals' => 5])['ovr'];
        $attGain = $att(40) - $att(5);
        $this->assertGreaterThan(0, $midGain);
        $this->assertGreaterThan($midGain, $attGain);
    }

    public function test_few_matches_pull_every_average_towards_the_team_so_a_lucky_debut_is_not_a_legend(): void
    {
        $oneGame = $this->rate('attaccante', ['matches' => 1, 'goals' => 4, 'wins' => 1, 'win_rate' => 1.0]);
        $season = $this->rate('attaccante', ['matches' => 30, 'goals' => 45, 'wins' => 22, 'win_rate' => 0.73]);

        $this->assertLessThan($season['ovr'], $oneGame['ovr']);
        $this->assertLessThan(88, $oneGame['ovr']);
    }

    public function test_cards_cost_points_but_never_more_than_a_little(): void
    {
        $clean = $this->rate('centrocampista', ['matches' => 40, 'goals' => 10, 'wins' => 20, 'win_rate' => 0.5, 'conceded' => 60]);
        $dirty = $this->rate('centrocampista', ['matches' => 40, 'goals' => 10, 'wins' => 20, 'win_rate' => 0.5, 'conceded' => 60, 'yellow' => 30, 'red' => 5]);

        $this->assertGreaterThan($dirty['ovr'], $clean['ovr']);
        $this->assertLessThanOrEqual(2, $clean['ovr'] - $dirty['ovr']);
    }

    public function test_the_breakdown_adds_up_to_the_rating_and_names_the_role(): void
    {
        $r = $this->rate('difensore', ['matches' => 25, 'goals' => 3, 'wins' => 12, 'win_rate' => 0.48, 'mvp' => 2, 'yellow' => 4, 'conceded' => 30, 'clean_sheets' => 6]);

        $this->assertSame('difensore', $r['role']);
        $this->assertSame(25, $r['matches']);
        $sum = array_sum(array_column($r['parts'], 'points')) - $r['penalty']['points'];
        $this->assertEqualsWithDelta($r['ovr'], CardRating::MIN + round($sum), 1.0);

        foreach ($r['parts'] as $part) {
            $this->assertGreaterThanOrEqual(0, $part['score']);
            $this->assertLessThanOrEqual(1, $part['score']);
            $this->assertArrayHasKey('label', $part);
            $this->assertArrayHasKey('value', $part);
            $this->assertArrayHasKey('weight', $part);
        }
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($r['parts'], 'weight')), 0.001, 'i pesi fanno 100%');
    }

    public function test_without_a_role_the_midfielder_formula_applies(): void
    {
        $totals = ['matches' => 20, 'goals' => 6, 'wins' => 10, 'win_rate' => 0.5, 'conceded' => 30];

        $this->assertSame($this->rate('centrocampista', $totals)['ovr'], $this->rate(null, $totals)['ovr']);
        $this->assertSame($this->rate('centrocampista', $totals)['ovr'], $this->rate('dirigente', $totals)['ovr']);
    }
}
