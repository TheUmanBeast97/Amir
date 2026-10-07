<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Player;
use App\Models\Team;
use App\Services\Xfive\XfiveSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeXfive();
        app(XfiveSyncService::class)->run('current');
    }

    public function test_home_shows_the_only_official_match_and_warns_that_the_calendar_is_incomplete(): void
    {
        $data = $this->getJson('/api/v1/public/home')->assertOk()->json('data');

        $this->assertSame('AMIR COSTRUZIONI', $data['team']['name']);
        $this->assertSame('Cittadella [Alessandria]', $data['competition']['name']);

        $next = $data['next_match'];
        $this->assertSame('VALONS', $next['away_team']['name']);
        $this->assertSame('2026-10-15T20:00:00+02:00', $next['kickoff_at']);
        $this->assertFalse($next['is_provisional']);
        $this->assertTrue($next['is_own_match']);
        $this->assertNotNull($next['event_id']);

        $info = $data['calendar_info'];
        $this->assertFalse($info['is_complete']);
        $this->assertSame(1, $info['scheduled_rounds']);
        $this->assertSame(18, $info['total_rounds']);
        $this->assertStringContainsString('1 giornate su 18', $info['note']);

        $this->assertNull($data['last_match']);
        $this->assertNull($data['standing'], 'a stagione non iniziata non esiste una posizione');
        $this->assertCount(10, $data['standings']);
        $this->assertSame([], $data['recent']);
    }

    public function test_upcoming_lists_the_official_match_before_the_provisional_ones(): void
    {
        $upcoming = $this->getJson('/api/v1/public/home')->json('data.upcoming');

        $this->assertCount(5, $upcoming);
        $this->assertFalse($upcoming[0]['is_provisional']);
        $this->assertTrue($upcoming[1]['is_provisional']);
        $this->assertNull($upcoming[1]['kickoff_at']);
        $this->assertSame('to_schedule', $upcoming[1]['status']);
    }

    public function test_own_matches_are_eighteen_one_official_and_seventeen_provisional(): void
    {
        $matches = $this->getJson('/api/v1/public/matches')->assertOk()->json('data');

        $this->assertCount(18, $matches);
        $this->assertCount(1, array_filter($matches, fn ($m) => ! $m['is_provisional']));
        $this->assertCount(17, array_filter($matches, fn ($m) => $m['is_provisional']));

        $all = $this->getJson('/api/v1/public/matches?scope=all')->json('data');
        $this->assertCount(90, $all);
    }

    public function test_the_phone_calendar_only_contains_matches_with_an_official_date(): void
    {
        $response = $this->get('/api/v1/public/calendar.ics')->assertOk();

        $this->assertStringContainsString('text/calendar', $response->headers->get('Content-Type'));
        $ics = $response->getContent();

        $this->assertSame(1, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('SUMMARY:AMIR COSTRUZIONI - VALONS', $ics);
        $this->assertStringContainsString('DTSTART:20261015T180000Z', $ics);
        $this->assertStringContainsString('LOCATION:100GRIGIO - CAMPO 4', $ics);
        $this->assertStringContainsString("\r\n", $ics);
    }

    public function test_the_public_roster_never_exposes_personal_data(): void
    {
        Player::create([
            'team_id' => Team::own()->id, 'first_name' => 'Mario', 'last_name' => 'Demo',
            'phone' => '3330000000', 'email' => 'demo@example.com', 'birth_date' => '1995-03-05',
            'medical_cert_expires_on' => '2027-01-01', 'notes' => 'riservato',
        ]);

        $json = $this->getJson('/api/v1/public/roster')->assertOk()->json('data');

        $this->assertCount(1, $json);
        $this->assertSame('Mario Demo', $json[0]['full_name']);
        foreach (['phone', 'email', 'birth_date', 'medical_cert_expires_on', 'notes', 'access_token', 'magic_link'] as $private) {
            $this->assertArrayNotHasKey($private, $json[0]);
        }
    }

    public function test_history_lists_past_seasons_after_importing_them(): void
    {
        app(XfiveSyncService::class)->run('history');

        $data = $this->getJson('/api/v1/public/history')->assertOk()->json('data');

        $this->assertCount(1, $data['seasons']);
        $this->assertSame('2025/2026', $data['seasons'][0]['season']);
        $this->assertSame('Uispic Premier', $data['seasons'][0]['competitions'][0]['name']);
        $this->assertGreaterThan(0, $data['summary']['record']['played']);
        $this->assertSame(1, $data['summary']['seasons_count']);
    }

    public function test_history_competition_detail_has_standings_and_own_matches(): void
    {
        app(XfiveSyncService::class)->run('history');
        $id = Competition::where('xfive_tournament_id', 144)->value('id');

        $data = $this->getJson("/api/v1/public/history/competitions/{$id}")->assertOk()->json('data');

        $this->assertSame('Uispic Premier', $data['competition']['name']);
        $this->assertNotEmpty($data['standings']);
        $this->assertNotEmpty($data['own_matches']);
        $this->assertTrue(collect($data['own_matches'])->every(fn ($m) => $m['is_own_match']));
        $this->assertGreaterThanOrEqual(count($data['own_matches']), count($data['all_matches']));
    }

    public function test_head_to_head_refuses_our_own_team_and_answers_for_opponents(): void
    {
        $own = Team::own();
        $valons = Team::where('name', 'VALONS')->firstOrFail();

        $this->getJson("/api/v1/public/head-to-head/{$own->id}")->assertNotFound();

        $h2h = $this->getJson("/api/v1/public/head-to-head/{$valons->id}")->assertOk()->json('data');
        $this->assertSame('VALONS', $h2h['opponent']['name']);
        $this->assertSame(0, $h2h['played']);
    }

    public function test_the_root_is_a_json_index_not_a_web_page(): void
    {
        $this->get('/')->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['app', 'api', 'try' => ['home', 'roster', 'history', 'calendar_ics']]);
    }

    public function test_errors_are_always_json(): void
    {
        $this->get('/api/v1/public/history/competitions/999999', ['Accept' => 'text/html'])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }
}
