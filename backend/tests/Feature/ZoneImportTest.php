<?php

namespace Tests\Feature;

use App\Models\Zone\XfClub;
use App\Models\Zone\XfDocument;
use App\Models\Zone\XfMatch;
use App\Models\Zone\XfMatchPlayer;
use App\Models\Zone\XfPlayer;
use App\Models\Zone\XfPlayerStat;
use App\Models\Zone\XfSeason;
use App\Models\Zone\XfStanding;
use App\Models\Zone\XfSyncState;
use App\Models\Zone\XfTeamPlayer;
use App\Models\Zone\XfTournament;
use App\Services\Zone\ZoneImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Le tabelle xf_* della Mixed Zone e l'importazione dei dati XFive dall'archivio (fixture sintetiche in tests/Fixtures/zone). */
class ZoneImportTest extends TestCase
{
    use RefreshDatabase;

    private function zoneFixture(string $rel): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/zone/{$rel}")), true);
    }

    public function test_the_zone_tables_exist_with_xfive_ids_as_keys(): void
    {
        $t = XfTournament::create(['id' => 187, 'season_id' => 8, 'name' => 'CITTADELLA', 'slug' => 'cittadella', 'sport' => 'Calcio a 8 - Maschile', 'format' => 8]);
        $this->assertSame(187, $t->refresh()->id);
        XfSyncState::create(['section' => 'zone-calendar', 'cursor' => ['tournament' => 187]]);
        $this->assertSame(['tournament' => 187], XfSyncState::find('zone-calendar')->cursor);
    }

    public function test_only_football_tournaments_are_imported(): void
    {
        $imp = app(ZoneImporter::class);
        $this->assertNotNull($imp->tournament($this->zoneFixture('tournaments/901/tournament.json')));
        $this->assertNull($imp->tournament(['id' => 902, 'exists' => true, 'name' => 'Padel', 'slug' => 'padel', 'season_id' => 8, 'season' => '2026/2027', 'sport' => 'Padel doppio - Maschile']));
        $this->assertNull($imp->tournament(['id' => 903, 'exists' => false]), 'un id che su XFive non è un torneo');
        $this->assertSame([901], XfTournament::pluck('id')->all());
        $this->assertSame(['format' => 7, 'gender' => 'Maschile'], XfTournament::find(901)->only('format', 'gender'));
        $this->assertSame('ongoing', XfTournament::find(901)->status);
        $this->assertSame('2026/2027', XfSeason::find(8)->label);

        $this->assertTrue(ZoneImporter::isFootball('Calcio a 7 Over - Maschile'));
        $this->assertFalse(ZoneImporter::isFootball('Pallavolo - Misto'));
        $this->assertSame('caffe km0', ZoneImporter::normalize('  CAFFÈ   KM0 '));
    }

    public function test_calendar_standings_stats_and_docs_are_imported_and_rerunnable(): void
    {
        $imp = app(ZoneImporter::class);
        $imp->tournament($this->zoneFixture('tournaments/901/tournament.json'));
        foreach ([1, 2] as $run) {
            $imp->calendar(901, $this->zoneFixture('tournaments/901/calendar.json'), '2026/2027');
            $imp->standings(901, $this->zoneFixture('tournaments/901/standings.json'));
            $imp->playerStats(901, $this->zoneFixture('tournaments/901/player-stats.json'));
            $imp->documents(901, $this->zoneFixture('tournaments/901/docs.json'));
        }
        $this->assertSame(3, XfMatch::count());
        $this->assertSame(3, XfClub::count(), 'i club nascono dal calendario');
        $this->assertSame('2026-10-12 21:00', XfMatch::find(90001)->kickoff_at->setTimezone('Europe/Rome')->format('Y-m-d H:i'));
        $this->assertTrue(XfMatch::find(90001)->played);
        $this->assertFalse(XfMatch::find(90003)->played);
        $this->assertSame([301, 302, 'CAFFÈ KM0', 1, '1ª giornata'], array_values(XfMatch::find(90001)->only(['home_club_id', 'away_club_id', 'home_name', 'round', 'round_label'])));
        $this->assertSame(['Girone A' => 2, 'Girone B' => 1], XfStanding::where('tournament_id', 901)->get()->countBy('group')->all());
        $this->assertSame(301, XfStanding::where('name', 'CAFFÈ KM0')->first()->club_id);
        $this->assertSame(['Pt' => 4, 'G' => 2, 'V' => 1, 'N' => 1, 'P' => 0, 'F' => 5, 'S' => 3, '+/-' => 2, 'FP' => 0], XfStanding::where('name', 'CAFFÈ KM0')->first()->values);
        $this->assertSame(1, XfPlayerStat::where('type', 'score')->count());
        $this->assertSame([2], XfPlayerStat::where('type', 'score')->first()->values);
        $this->assertSame(1, XfDocument::count());
    }

    public function test_profile_names_lose_the_shirt_number_and_the_role_xfive_appends(): void
    {
        $this->assertSame('Verdi Luca', ZoneImporter::cleanPlayerName('Verdi Luca 9'));
        $this->assertSame('Zunino Mattia', ZoneImporter::cleanPlayerName('Zunino Mattia Centrocampista 7'));
        $this->assertSame('Rossi Mario', ZoneImporter::cleanPlayerName('Rossi Mario Portiere'));
        $this->assertSame('Punta Paolo', ZoneImporter::cleanPlayerName('Punta Paolo'), 'un cognome che è anche un ruolo non si tocca se non è in coda');
        $this->assertSame('Ala', ZoneImporter::cleanPlayerName('Ala'));
    }

    public function test_teams_clubs_and_profiles_link_tournament_players_to_global_profiles(): void
    {
        $imp = app(ZoneImporter::class);
        $imp->tournament($this->zoneFixture('tournaments/901/tournament.json'));
        $imp->team($this->zoneFixture('teams/5001.json'));
        $this->assertNull(XfTeamPlayer::where('tpid', 61001)->first()->player_id, 'senza il club non si conosce il profilo');
        $imp->club($this->zoneFixture('clubs/301.json'));
        $this->assertSame(7001, XfTeamPlayer::where('tpid', 61001)->first()->player_id);
        $this->assertSame(7002, XfTeamPlayer::where('tpid', 61002)->first()->player_id);

        // rileggere la squadra non perde l'abbinamento, e un'altra squadra dello stesso club lo eredita dallo slug
        $imp->team($this->zoneFixture('teams/5001.json'));
        $this->assertSame(7001, XfTeamPlayer::where('tpid', 61001)->first()->player_id);
        $imp->team(['id' => 5009, 'tournament_id' => 901, 'name' => 'CAFFÈ KM0', 'club_id' => 301, 'players' => [['id' => 61501, 'slug' => 'luca-verdi', 'name' => 'Verdi Luca']]]);
        $this->assertSame(7001, XfTeamPlayer::where('tpid', 61501)->first()->player_id);

        $imp->player($this->zoneFixture('players/7001.json'));
        $p = XfPlayer::find(7001);
        $this->assertSame(['Verdi Luca', 30, 'Italia'], [$p->name, $p->age, $p->nationality]);
        $this->assertSame(901, $p->profile[0]['tournaments'][0]['id']);
        $this->assertNotNull($p->synced_at);
    }

    public function test_reports_fill_lineups_and_keep_unknown_players(): void
    {
        $imp = app(ZoneImporter::class);
        $imp->tournament($this->zoneFixture('tournaments/901/tournament.json'));
        $imp->calendar(901, $this->zoneFixture('tournaments/901/calendar.json'), '2026/2027');
        $imp->team($this->zoneFixture('teams/5001.json'));
        $imp->club($this->zoneFixture('clubs/301.json'));
        $imp->report($this->zoneFixture('matches/90001.json'));
        $imp->report($this->zoneFixture('matches/90001.json'));

        $m = XfMatch::find(90001);
        $this->assertTrue($m->has_report);
        $this->assertSame('Arbitro Di Prova', $m->referee);
        $rows = XfMatchPlayer::where('match_id', 90001)->get();
        $this->assertCount(3, $rows);
        $this->assertSame(7001, $rows->firstWhere('tpid', 61001)->player_id);
        $this->assertNull($rows->firstWhere('tpid', 61999)->player_id, 'il giocatore fuori rosa resta nel referto');
        $this->assertSame('away', $rows->firstWhere('tpid', 61999)->side);
        $this->assertTrue($rows->firstWhere('tpid', 61001)->mvp);
        $this->assertSame(2, $rows->firstWhere('tpid', 61001)->goals);

        // un referto di una partita che non è in calendario non si scrive
        $this->assertSame(1, $imp->report(['id' => 99999, 'tournament_id' => 901, 'home' => ['lineup' => []], 'away' => ['lineup' => []]])['skipped']);
        $this->assertSame(0, XfMatchPlayer::where('match_id', 99999)->count());
    }
}
