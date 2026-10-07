<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private Team $own;

    protected function setUp(): void
    {
        parent::setUp();
        $this->own = Team::create(['name' => 'AMIR COSTRUZIONI', 'short_name' => 'AMIR', 'xfive_club_id' => 159, 'is_own' => true, 'format' => 8]);
    }

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function player(string $first = 'Mario', string $last = 'Demo', array $extra = []): Player
    {
        return Player::create(['team_id' => $this->own->id, 'first_name' => $first, 'last_name' => $last] + $extra);
    }

    private function game(string $status = Game::SCHEDULED): Game
    {
        $rival = Team::create(['name' => 'VALONS', 'xfive_club_id' => 432]);
        $comp = Competition::create([
            'xfive_tournament_id' => 187, 'name' => 'Cittadella', 'season' => '2026/2027',
            'kind' => 'campionato', 'format' => 8, 'is_current' => true, 'has_own_team' => true,
        ]);

        return Game::create([
            'competition_id' => $comp->id, 'round' => 1, 'round_label' => '1ª giornata',
            'home_team_id' => $this->own->id, 'away_team_id' => $rival->id,
            'kickoff_at' => now()->addDays(5), 'venue' => '100GRIGIO - CAMPO 4', 'status' => $status,
        ]);
    }

    // ---------- accesso ----------

    public function test_admin_endpoints_require_a_token(): void
    {
        foreach (['/players', '/dashboard', '/charges', '/finance/summary', '/stats/attendance', '/sync/runs', '/matches'] as $path) {
            $this->getJson('/api/v1'.$path)->assertUnauthorized();
        }
    }

    public function test_a_request_without_the_json_accept_header_still_gets_a_json_401(): void
    {
        $this->get('/api/v1/dashboard')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json');

        $this->get('/api/v1/dashboard', ['Accept' => '*/*'])->assertUnauthorized();
    }

    public function test_login_gives_a_token_and_rejects_wrong_passwords(): void
    {
        User::factory()->create(['email' => 'capo@example.com', 'password' => 'una-password-lunga-1']);

        $this->postJson('/api/v1/auth/login', ['email' => 'capo@example.com', 'password' => 'sbagliata'])
            ->assertUnprocessable();

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'capo@example.com', 'password' => 'una-password-lunga-1'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email']]])
            ->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'capo@example.com');
    }

    // ---------- giocatori ----------

    public function test_players_can_be_created_updated_and_deactivated_not_lost(): void
    {
        $this->admin();

        $id = $this->postJson('/api/v1/players', [
            'first_name' => 'Luca', 'last_name' => 'Demo', 'role' => 'portiere', 'shirt_number' => '1',
            'birth_date' => '1996-02-10', 'phone' => '3330001111',
        ])->assertCreated()->assertJsonPath('data.full_name', 'Luca Demo')->json('data.id');

        $this->patchJson("/api/v1/players/{$id}", ['registration_status' => 'approved'])
            ->assertOk()->assertJsonPath('data.registration_status', 'approved');

        $this->deleteJson("/api/v1/players/{$id}")->assertOk();
        $this->assertFalse((bool) Player::find($id)->is_active, 'si disattiva, non si cancella');

        $this->postJson('/api/v1/players', ['first_name' => 'X', 'last_name' => 'Y', 'role' => 'portierone'])
            ->assertUnprocessable();
    }

    public function test_squad_list_accepts_at_most_ten_players(): void
    {
        $this->admin();

        foreach (range(1, 10) as $i) {
            $this->player("Giocatore{$i}", 'Squad', ['in_squad_list' => true]);
        }
        $eleventh = $this->player('Undicesimo', 'Fuori');

        $this->patchJson("/api/v1/players/{$eleventh->id}", ['in_squad_list' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('in_squad_list');

        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.squad_list.count', 10)
            ->assertJsonPath('data.squad_list.limit', 10);
    }

    public function test_import_understands_italian_headers_and_updates_instead_of_duplicating(): void
    {
        $this->admin();

        $rows = [
            ['Cognome' => 'Rossi', 'Nome' => 'Mario', 'Data di nascita' => '05/03/1995', 'Ruolo' => 'Portiere', 'Numero' => '1', 'Tesseramento' => 'In attesa'],
            ['Giocatore' => 'Bianchi Luca', 'Ruolo' => 'attaccante'],
            ['Cognome' => 'SenzaNome'],
        ];

        $first = $this->postJson('/api/v1/players/import', ['rows' => $rows])->assertOk()->json('data');
        $this->assertSame(2, $first['created']);
        $this->assertSame(1, $first['skipped']);

        $mario = Player::where('last_name', 'Rossi')->firstOrFail();
        $this->assertSame('1995-03-05', $mario->birth_date->toDateString());
        $this->assertSame('portiere', $mario->role);
        $this->assertSame('pending', $mario->registration_status);
        $this->assertSame('Bianchi', Player::where('first_name', 'Luca')->value('last_name'));

        $second = $this->postJson('/api/v1/players/import', ['rows' => array_slice($rows, 0, 2)])->json('data');
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['updated']);
        $this->assertSame(2, Player::count());
    }

    // ---------- presenze e link personale ----------

    public function test_a_player_answers_from_the_personal_link_without_logging_in(): void
    {
        $mario = $this->player();
        $event = TeamEvent::create([
            'team_id' => $this->own->id, 'type' => 'training', 'title' => 'Allenamento', 'starts_at' => now()->addDay(),
        ]);
        $token = $mario->access_token;

        $this->getJson("/api/v1/me/{$token}")->assertOk()
            ->assertJsonPath('data.player.full_name', 'Mario Demo')
            ->assertJsonPath('data.upcoming_events.0.my_rsvp', null)
            ->assertJsonPath('data.balance.balance_cents', 0);

        $this->postJson("/api/v1/me/{$token}/events/{$event->id}/rsvp", ['rsvp' => 'yes', 'note' => 'arrivo tardi'])
            ->assertOk()
            ->assertJsonPath('data.my_rsvp', 'yes')
            ->assertJsonPath('data.summary.yes', 1);

        $this->postJson("/api/v1/me/{$token}/events/{$event->id}/rsvp", ['rsvp' => 'forse'])->assertUnprocessable();
        $this->getJson('/api/v1/me/token-inventato')->assertNotFound();
    }

    public function test_the_reminder_names_who_has_not_answered(): void
    {
        $this->admin();
        $yes = $this->player('Risponde', 'Subito');
        $silent = $this->player('Silenzioso', 'Mai');
        $event = TeamEvent::create([
            'team_id' => $this->own->id, 'type' => 'training', 'title' => 'Allenamento', 'starts_at' => now()->addDay(),
        ]);

        $this->putJson("/api/v1/events/{$event->id}/responses/{$yes->id}", ['rsvp' => 'yes'])->assertOk();

        $reminder = $this->postJson("/api/v1/events/{$event->id}/remind")->assertOk()->json('data');

        $this->assertStringContainsString('Silenzioso Mai', $reminder['text']);
        $this->assertStringNotContainsString('Risponde Subito', $reminder['text']);
        $this->assertStringStartsWith('https://wa.me/?text=', $reminder['wa_link']);
        $this->assertCount(1, $reminder['missing']);
    }

    public function test_match_events_cannot_be_edited_or_deleted_by_hand(): void
    {
        $this->admin();
        $game = $this->game();
        $event = TeamEvent::create([
            'team_id' => $this->own->id, 'type' => 'match', 'title' => 'AMIR - VALONS',
            'starts_at' => $game->kickoff_at, 'match_id' => $game->id,
        ]);

        $this->deleteJson("/api/v1/events/{$event->id}")->assertUnprocessable();
        $this->patchJson("/api/v1/events/{$event->id}", ['title' => 'altro'])->assertUnprocessable();
        $this->postJson('/api/v1/events', ['type' => 'match', 'title' => 'x', 'starts_at' => now()->addDay()->toDateTimeString()])->assertUnprocessable();
    }

    // ---------- pagamenti ----------

    public function test_finance_tracks_who_owes_what_and_what_was_paid(): void
    {
        $this->admin();
        $a = $this->player('Anna', 'Uno');
        $b = $this->player('Bruno', 'Due');
        $this->player('Chiara', 'Tre', ['is_active' => false]);

        $charge = $this->postJson('/api/v1/charges', [
            'title' => 'Quota stagione', 'kind' => 'quota_stagione', 'amount_cents' => 12000, 'due_on' => now()->addMonth()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.assigned_count', 2)->json('data');

        $this->assertSame(24000, $charge['total_due_cents']);

        $playerCharge = \App\Models\PlayerCharge::where('player_id', $a->id)->firstOrFail();
        $this->postJson("/api/v1/player-charges/{$playerCharge->id}/payments", [
            'amount_cents' => 5000, 'method' => 'satispay', 'paid_at' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.balance_cents', 7000);

        $summary = $this->getJson('/api/v1/finance/summary')->assertOk()->json('data');
        $this->assertSame(24000, $summary['total_due_cents']);
        $this->assertSame(5000, $summary['total_paid_cents']);
        $this->assertSame(19000, $summary['total_outstanding_cents']);
        $this->assertSame('Bruno Due', $summary['players'][0]['player_name'], 'chi deve di più sta in cima');

        $this->getJson("/api/v1/finance/players/{$a->id}")->assertOk()
            ->assertJsonPath('data.balance_cents', 7000)
            ->assertJsonPath('data.items.0.payments.0.method', 'satispay');

        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.finance.outstanding_cents', 19000);
    }

    public function test_an_overdue_charge_is_flagged(): void
    {
        $this->admin();
        $this->player();

        $this->postJson('/api/v1/charges', [
            'title' => 'Tesseramento', 'kind' => 'tesseramento', 'amount_cents' => 1000, 'due_on' => now()->subDays(3)->toDateString(),
        ])->assertCreated();

        $this->getJson('/api/v1/dashboard')->assertJsonPath('data.finance.overdue_count', 1);
        $this->assertTrue($this->getJson('/api/v1/finance/players/'.Player::first()->id)->json('data.items.0.is_overdue'));
    }

    // ---------- formazione e statistiche ----------

    private function lineupPayload(array $playerIds, string $formation = '3-3-1'): array
    {
        $slots = [];
        foreach (range(1, 8) as $n) {
            $slots[] = ['slot' => $n, 'player_id' => $playerIds[$n - 1] ?? null, 'label' => $n === 1 ? 'POR' : 'DIF', 'x' => 10 * $n, 'y' => 10 * $n];
        }

        return ['formation' => $formation, 'slots' => $slots, 'bench' => [], 'notes' => 'pressing alto'];
    }

    public function test_a_lineup_is_saved_only_when_formation_and_players_make_sense(): void
    {
        $this->admin();
        $game = $this->game();
        $ids = collect(range(1, 8))->map(fn ($i) => $this->player("P{$i}", 'Lineup')->id)->all();

        $this->putJson("/api/v1/matches/{$game->id}/lineup", $this->lineupPayload($ids))
            ->assertOk()->assertJsonPath('data.formation', '3-3-1');
        $this->getJson("/api/v1/matches/{$game->id}/lineup")->assertOk()->assertJsonPath('data.slots.0.label', 'POR');

        // 4-4-1 sono 9 giocatori di movimento: troppi per il calcio a 8
        $this->putJson("/api/v1/matches/{$game->id}/lineup", $this->lineupPayload($ids, '4-4-1'))
            ->assertUnprocessable()->assertJsonValidationErrors('formation');

        // stesso giocatore due volte
        $dup = $ids;
        $dup[2] = $ids[1];
        $this->putJson("/api/v1/matches/{$game->id}/lineup", $this->lineupPayload($dup))
            ->assertUnprocessable()->assertJsonValidationErrors('slots');

        // titolare e panchina insieme
        $payload = $this->lineupPayload($ids);
        $payload['bench'] = [$ids[0]];
        $this->putJson("/api/v1/matches/{$game->id}/lineup", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('bench');
    }

    public function test_after_match_stats_feed_the_team_leaderboard(): void
    {
        $this->admin();
        $game = $this->game(Game::PLAYED);
        $bomber = $this->player('Bomber', 'Demo');
        $bench = $this->player('Panchina', 'Demo');

        $this->putJson("/api/v1/matches/{$game->id}/stats", [
            'players' => [
                ['player_id' => $bomber->id, 'played' => true, 'goals' => 3, 'assists' => 1, 'yellow' => 1, 'rating' => 8.5],
                ['player_id' => $bench->id, 'played' => false],
            ],
            'referee' => 'Arbitro Demo',
            'man_of_the_match_id' => $bomber->id,
        ])->assertOk()
            ->assertJsonPath('data.referee', 'Arbitro Demo')
            ->assertJsonPath('data.man_of_the_match_id', $bomber->id)
            ->assertJsonCount(2, 'data.stats');

        $rows = $this->getJson('/api/v1/stats/attendance?season=2026/2027')->assertOk()->json('data');

        $this->assertSame('Bomber Demo', $rows[0]['full_name']);
        $this->assertSame(1, $rows[0]['matches_played']);
        $this->assertSame(3, $rows[0]['goals']);
        $this->assertSame(1, $rows[0]['assists']);
        $this->assertSame(8.5, $rows[0]['avg_rating']);
        $this->assertSame(0, $rows[1]['matches_played']);

        // l'elenco inviato è completo: chi manca viene tolto
        $this->putJson("/api/v1/matches/{$game->id}/stats", [
            'players' => [['player_id' => $bomber->id, 'played' => true, 'goals' => 1]],
        ])->assertOk()->assertJsonCount(1, 'data.stats');
    }

    public function test_match_detail_includes_responses_and_head_to_head(): void
    {
        $this->admin();
        $game = $this->game();
        $this->player();
        TeamEvent::create([
            'team_id' => $this->own->id, 'type' => 'match', 'title' => 'AMIR - VALONS',
            'starts_at' => $game->kickoff_at, 'match_id' => $game->id,
        ]);

        $this->getJson("/api/v1/matches/{$game->id}")->assertOk()
            ->assertJsonPath('data.match.away_team.name', 'VALONS')
            ->assertJsonPath('data.responses.0.rsvp', null)
            ->assertJsonPath('data.head_to_head.opponent.name', 'VALONS')
            ->assertJsonPath('data.lineup', null);
    }

    public function test_captions_follow_the_requested_tone_and_use_real_data(): void
    {
        $this->admin();
        $game = $this->game();

        $text = $this->postJson("/api/v1/matches/{$game->id}/caption", ['tone' => 'epico'])->assertOk()->json('data.text');
        $this->assertStringContainsString('AMIR', $text);
        $this->assertStringContainsString('VALONS', $text);

        $this->postJson("/api/v1/matches/{$game->id}/caption", ['tone' => 'sarcastico'])->assertUnprocessable();
    }

    public function test_the_demo_seeder_builds_a_usable_team_and_can_run_twice(): void
    {
        $this->admin();

        $this->seed(\Database\Seeders\DemoSeeder::class);
        $this->seed(\Database\Seeders\DemoSeeder::class);

        $this->assertSame(16, Player::count());
        $this->assertSame(10, Player::where('in_squad_list', true)->count());
        $this->assertSame(2, \App\Models\Charge::count());

        $summary = $this->getJson('/api/v1/finance/summary')->assertOk()->json('data');
        $this->assertGreaterThan(0, $summary['total_outstanding_cents']);
        $this->assertSame($summary['total_due_cents'] - $summary['total_paid_cents'], $summary['total_outstanding_cents']);
    }

    // ---------- dashboard e sincronizzazione ----------

    public function test_dashboard_reminds_the_xfive_rules_and_deadlines(): void
    {
        $this->admin();
        $this->player('Certificato', 'Scaduto', ['medical_cert_expires_on' => now()->addDays(10)->toDateString()]);

        $data = $this->getJson('/api/v1/dashboard')->assertOk()->json('data');

        $this->assertNotEmpty($data['rules']);
        $this->assertNotEmpty($data['deadlines']);
        $this->assertSame('never', $data['sync']['status']);
        $this->assertCount(1, $data['certificates_expiring']);
    }

    public function test_starting_a_sync_answers_202_and_the_run_completes(): void
    {
        $this->admin();
        $this->fakeXfive();

        $first = $this->postJson('/api/v1/sync/xfive', ['scope' => 'current'])->assertStatus(202)->json('data');
        $this->assertSame('running', $first['status']);

        // nei test il lavoro "dopo la risposta" parte subito: a fine richiesta la corsa è chiusa
        $run = \App\Models\SyncRun::findOrFail($first['id']);
        $this->assertSame('ok', $run->status);
        $this->assertGreaterThan(0, $run->stats['competitions']);

        $this->getJson('/api/v1/sync/runs')->assertOk()->assertJsonPath('data.0.id', $first['id']);
        $this->postJson('/api/v1/sync/xfive', ['scope' => 'tutto'])->assertUnprocessable();
    }

    public function test_a_sync_already_running_is_not_started_twice(): void
    {
        $this->admin();
        $running = \App\Models\SyncRun::create(['scope' => 'current', 'status' => 'running', 'started_at' => now(), 'stats' => []]);

        $this->postJson('/api/v1/sync/xfive', ['scope' => 'current'])
            ->assertStatus(202)
            ->assertJsonPath('data.id', $running->id);

        $this->assertSame(1, \App\Models\SyncRun::count());
    }
}
