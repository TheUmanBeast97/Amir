<?php

namespace Tests\Unit;

use App\Services\Xfive\ClubHistoryParser;
use App\Services\Xfive\CompetitionClassifier;
use App\Services\Xfive\KickoffParser;
use App\Services\Xfive\PrintableCalendarParser;
use Tests\TestCase;

class XfiveParsersTest extends TestCase
{
    public function test_calendar_parser_reads_every_match_of_the_partial_calendar(): void
    {
        $fixtures = (new PrintableCalendarParser)->parse($this->fixture('calendar_cittadella_2026.html'));

        $this->assertCount(90, $fixtures);

        $first = $fixtures[0];
        $this->assertSame(1, $first['round']);
        $this->assertSame('1ª giornata', $first['round_label']);
        $this->assertSame('CAFFÈ KM0-PALESTRA MEETING', $first['home']['name']);
        $this->assertSame(351, $first['home']['club_id']);
        $this->assertSame('TORNITURE KARIM', $first['away']['name']);
        $this->assertSame('lun 12/10 21:00', $first['kickoff_raw']);
        $this->assertSame('100GRIGIO - CAMPO 4', $first['venue']);
        $this->assertFalse($first['played']);
        $this->assertNull($first['home_score']);
    }

    public function test_only_the_first_round_has_date_and_time(): void
    {
        $fixtures = (new PrintableCalendarParser)->parse($this->fixture('calendar_cittadella_2026.html'));

        $dated = array_filter($fixtures, fn (array $f) => $f['kickoff_raw'] !== null);
        $this->assertCount(5, $dated);
        $this->assertSame([1], array_values(array_unique(array_column($dated, 'round'))));

        $amir = collect($dated)->first(fn (array $f) => $f['home']['club_id'] === 159);
        $this->assertSame('VALONS', $amir['away']['name']);
        $this->assertSame('gio 15/10 20:00', $amir['kickoff_raw']);
    }

    public function test_calendar_parser_reads_scores_of_played_matches(): void
    {
        $fixtures = (new PrintableCalendarParser)->parse($this->fixture('calendar_uispic_first_trimmed.html'));

        $played = array_values(array_filter($fixtures, fn (array $f) => $f['played']));
        $this->assertNotEmpty($played);
        $this->assertIsInt($played[0]['home_score']);
        $this->assertIsInt($played[0]['away_score']);
    }

    public function test_kickoff_parser_infers_the_year_from_the_season(): void
    {
        $autumn = KickoffParser::parse('gio 15/10 20:00', '2026/2027');
        $this->assertSame('2026-10-15T20:00:00+02:00', $autumn->toIso8601String());

        $winter = KickoffParser::parse('lun 18/01 21:30', '2026/2027');
        $this->assertSame('2027-01-18', $winter->toDateString());
        $this->assertSame('21:30', $winter->format('H:i'));
    }

    public function test_kickoff_parser_rejects_missing_or_impossible_dates(): void
    {
        $this->assertNull(KickoffParser::parse(null, '2026/2027'));
        $this->assertNull(KickoffParser::parse('', '2026/2027'));
        $this->assertNull(KickoffParser::parse('lun 31/02 20:00', '2026/2027'));
        $this->assertNull(KickoffParser::parse('gio 15/10 20:00', 'stagione'));
    }

    public function test_club_history_parser_lists_the_tournaments_of_a_season(): void
    {
        $json = json_decode($this->fixture('club_history_2025.json'), true);
        $tournaments = (new ClubHistoryParser)->parse($json['html']);

        $ids = array_column($tournaments, 'id');
        sort($ids);
        $this->assertSame([139, 144, 150, 158, 165, 171], $ids);

        $byId = array_column($tournaments, null, 'id');
        $this->assertSame(7, $byId[144]['format']);
        $this->assertSame(8, $byId[139]['format']);
        $this->assertSame('Uispic Premier', $byId[144]['name']);
    }

    public function test_club_history_parser_tolerates_an_empty_season(): void
    {
        $this->assertSame([], (new ClubHistoryParser)->parse(''));
    }

    public function test_competition_classifier(): void
    {
        $this->assertSame('coppa_lega', CompetitionClassifier::kind('Coppa Di Lega Uispic League'));
        $this->assertSame('coppa_categoria', CompetitionClassifier::kind('Coppa Di Categoria C8'));
        $this->assertSame('coppa', CompetitionClassifier::kind('Gazzetta Cup C7'));
        $this->assertSame('torneo', CompetitionClassifier::kind('Memorial Carta'));
        $this->assertSame('campionato', CompetitionClassifier::kind('Cittadella [alessandria]'));

        $this->assertSame('Cittadella [Alessandria]', CompetitionClassifier::cleanName('Cittadella [alessandria]'));
    }
}
