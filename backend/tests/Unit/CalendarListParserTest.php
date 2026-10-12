<?php

namespace Tests\Unit;

use App\Services\Xfive\CalendarListParser;
use App\Support\Kickoff;
use Tests\TestCase;

/** L'elenco partite delle coppe a tabellone (league.php op=22): id, fase, data senza anno, squadre e punteggi. */
class CalendarListParserTest extends TestCase
{
    public function test_cup_matches_are_read_with_their_ids_scores_and_dates(): void
    {
        $rows = (new CalendarListParser)->parse($this->fixture('cup-calendar.html'), 159);

        $this->assertCount(3, $rows);
        $first = $rows[0];
        $this->assertSame([13136, 'Ottavo 5', 159, true], [$first['xfive_match_id'], $first['round_label'], $first['tournament_id'], $first['played']]);
        $this->assertSame(['REY GOMME', 'PALESTRA MEETING 2025'], [$first['home']['name'], $first['away']['name']]);
        $this->assertSame([3, 6], [$first['home_score'], $first['away_score']]);
        $this->assertSame('100GRIGIO - CAMPO 4', $first['venue']);
        $this->assertSame('Mer 08 APR 22:00', $first['kickoff_raw']);
        $this->assertNull($first['home']['club_id'], 'le coppe non hanno gli id dei club: si ricavano dal nome');
        $this->assertStringContainsString('/img/team/badge/', (string) $first['home']['badge_url']);
    }

    public function test_a_match_without_scores_is_not_played(): void
    {
        $rows = (new CalendarListParser)->parse($this->fixture('cup-calendar.html'), 159);

        $this->assertFalse($rows[2]['played']);
        $this->assertSame([null, null], [$rows[2]['home_score'], $rows[2]['away_score']]);
    }

    public function test_the_same_match_is_never_read_twice_and_other_html_gives_nothing(): void
    {
        $html = $this->fixture('cup-calendar.html');
        $this->assertCount(3, (new CalendarListParser)->parse($html.$html, 159));
        $this->assertSame([], (new CalendarListParser)->parse('<div>Non ci sono ancora dati</div>'));
    }

    public function test_dates_written_with_the_month_in_letters_get_the_season_year(): void
    {
        $this->assertSame('2026-04-08 22:00', Kickoff::parse('Mer 08 APR 22:00', '2025/2026')?->format('Y-m-d H:i'));
        $this->assertSame('2025-12-18 21:30', Kickoff::parse('Gio 18 DIC 21:30', '2025/2026')?->format('Y-m-d H:i'));
        $this->assertSame('2026-01-05 00:00', Kickoff::parse('05 gen', '2025/2026')?->format('Y-m-d H:i'));
        $this->assertNull(Kickoff::parse('Gio 18 XYZ 21:30', '2025/2026'));
    }
}
