<?php

namespace Tests\Unit;

use App\Services\Xfive\MatchPageParser;
use App\Support\PersonName;
use Tests\TestCase;

/** Pagine partita di esempio con la struttura di XFive e nomi inventati. */
class MatchPageParserTest extends TestCase
{
    private function page(string $name): array
    {
        return (new MatchPageParser)->parse(file_get_contents(__DIR__.'/../Fixtures/xfive/'.$name.'.html'));
    }

    public function test_it_reads_referee_venue_score_and_both_lineups(): void
    {
        $page = $this->page('match-with-referee-and-mvp');

        $this->assertSame('Arbitro Esempio', $page['referee']);
        $this->assertSame('DON STORNINI - UISPIC ARENA', $page['venue']);
        $this->assertSame([7, 8], [$page['home_score'], $page['away_score']]);
        $this->assertCount(8, $page['home']['lineup']);
        $this->assertCount(9, $page['away']['lineup']);
    }

    public function test_goals_come_from_the_player_icons_and_match_the_score(): void
    {
        $page = $this->page('match-with-referee-and-mvp');

        $this->assertSame(7, array_sum(array_column($page['home']['lineup'], 'goals')));
        $this->assertSame(8, array_sum(array_column($page['away']['lineup'], 'goals')));

        $blu = collect($page['away']['lineup'])->firstWhere('name', 'Blu Andrea');
        $this->assertSame(2, $blu['goals'], 'l\'icona "Goal" seguita da x2');
        $this->assertSame('andrea-blu', $blu['slug']);
        $this->assertSame(49059, $blu['ref']);
    }

    public function test_the_scorer_list_lists_the_number_before_or_after_the_name(): void
    {
        $page = $this->page('match-with-referee-and-mvp');

        // squadra di casa: "Nome (3)"; ospite: "(2) Nome"
        $this->assertSame(['Verdi Luca' => 3, 'Neri Paolo' => 2, 'Gialli Marco' => 2], array_column($page['home']['scorers'], 'goals', 'name'));
        $this->assertSame(2, collect($page['away']['scorers'])->firstWhere('name', 'Blu Andrea')['goals']);
        $this->assertSame(1, collect($page['away']['scorers'])->firstWhere('name', 'Marrone Stefano')['goals'], 'senza numero = un gol');
    }

    public function test_the_best_player_star_is_read(): void
    {
        $page = $this->page('match-with-referee-and-mvp');

        $this->assertSame(['Neri Paolo'], array_column(array_filter($page['home']['lineup'], fn ($p) => $p['mvp']), 'name'));
        $this->assertSame(['Grigi Simone'], array_column(array_filter($page['away']['lineup'], fn ($p) => $p['mvp']), 'name'));
    }

    public function test_yellow_and_red_cards_are_read(): void
    {
        $page = $this->page('match-with-red-card');

        $this->assertSame(3, array_sum(array_column($page['home']['lineup'], 'yellow')));
        $this->assertSame(1, array_sum(array_column($page['away']['lineup'], 'red')));
        $this->assertSame(0, array_sum(array_column($page['away']['lineup'], 'yellow')));
        $this->assertSame('Costa Mirko', collect($page['away']['lineup'])->firstWhere('red', 1)['name']);
    }

    public function test_a_match_without_lineup_gives_empty_lists_and_no_referee(): void
    {
        $page = $this->page('match-without-lineup');

        $this->assertNull($page['referee']);
        $this->assertSame('QUARTIERUZZI - CAMPO c8', $page['venue']);
        $this->assertSame([3, 1], [$page['home_score'], $page['away_score']]);
        $this->assertSame([], $page['home']['lineup']);
        $this->assertSame([], $page['away']['scorers']);
    }

    // ---------- nomi ----------

    public function test_names_are_compared_without_caring_about_order_accents_or_case(): void
    {
        $this->assertSame(PersonName::key('Rossi Mario'), PersonName::key('mario ROSSI'));
        $this->assertSame(PersonName::key('Leone Niccolò'), PersonName::key('Niccolo Leone'));
        $this->assertNotSame(PersonName::key('Fracchia Daniele'), PersonName::key('Fracchia Edoardo'));
    }

    public function test_the_slug_tells_surname_from_given_names(): void
    {
        $this->assertSame(['Fracchia', 'Edoardo Giovanni'], PersonName::split('Fracchia Edoardo Giovanni', 'edoardo-giovanni-fracchia'));
        $this->assertSame(['Di Benedetto', 'Carlo'], PersonName::split('Di Benedetto Carlo', 'carlo-di-benedetto'));
        $this->assertSame(['Xassan Cali', 'Yassin'], PersonName::split('Xassan Cali Yassin', 'yassin-xassan-cali'));
    }

    public function test_without_a_slug_particles_stay_with_the_surname(): void
    {
        $this->assertSame(['Rossi', 'Mario'], PersonName::split('Rossi Mario'));
        $this->assertSame(['De Luca', 'Marco'], PersonName::split('De Luca Marco'));
        $this->assertSame(['Rossi', ''], PersonName::split('Rossi'));
    }
}
