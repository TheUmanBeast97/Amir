<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Game;
use App\Models\MatchPlayerStat;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\TextGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/** Pagina del giocatore: compagni d'oro, vittime, traguardi, serie in corso, scheda scout e compleanni. */
class PlayerExtrasTest extends TestCase
{
    use RefreshDatabase;

    private Team $own;

    private Player $pino;

    private Player $sara;

    private Player $quinto;

    private Player $rocco;

    /** @var array<int, Game> partite numerate da 1 a 8 */
    private array $games = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->own = Team::create(['name' => 'AMIR', 'xfive_club_id' => 159, 'is_own' => true]);
        $x = Team::create(['name' => 'XRAY', 'xfive_club_id' => 2]);
        $y = Team::create(['name' => 'YANKEE', 'xfive_club_id' => 3]);
        $league = Competition::create(['xfive_tournament_id' => 10, 'name' => 'Lega', 'season' => '2025/2026', 'kind' => 'campionato', 'format' => 8, 'is_current' => false, 'has_own_team' => true]);

        $this->pino = Player::create(['team_id' => $this->own->id, 'first_name' => 'Pino', 'last_name' => 'Primo', 'role' => 'attaccante', 'is_active' => true]);
        $this->sara = Player::create(['team_id' => $this->own->id, 'first_name' => 'Sara', 'last_name' => 'Seconda', 'is_active' => true]);
        $this->quinto = Player::create(['team_id' => $this->own->id, 'first_name' => 'Quinto', 'last_name' => 'Quarto', 'is_active' => true]);
        $this->rocco = Player::create(['team_id' => $this->own->id, 'first_name' => 'Rocco', 'last_name' => 'Rosso', 'is_active' => true]);

        // AMIR in casa, contro XRAY nelle partite dispari e YANKEE in quelle pari: V V N P V V P V
        $scores = [[3, 0], [2, 1], [1, 1], [0, 2], [4, 1], [1, 0], [1, 3], [2, 0]];
        foreach ($scores as $i => [$for, $against]) {
            $this->games[$i + 1] = Game::create([
                'competition_id' => $league->id, 'round' => $i + 1, 'round_label' => ($i + 1).'ª giornata',
                'home_team_id' => $this->own->id, 'away_team_id' => ($i % 2 === 0 ? $x : $y)->id,
                'home_score' => $for, 'away_score' => $against, 'kickoff_at' => Carbon::parse('2025-10-01 21:00:00')->addDays($i * 7), 'status' => Game::PLAYED,
            ]);
        }

        $goals = [1 => 2, 3 => 1, 5 => 1]; // Pino: 4 gol, poi 3 partite a secco
        foreach ($this->games as $n => $game) {
            $this->played($game, $this->pino, $goals[$n] ?? 0);
        }
        foreach ([1, 2, 3, 4, 5, 6, 7] as $n) { // 7 partite insieme a Pino: V V N P V V P
            $this->played($this->games[$n], $this->quinto);
        }
        foreach ([1, 2, 3, 5, 6, 8] as $n) { // 6 partite insieme a Pino: V V N V V V
            $this->played($this->games[$n], $this->sara);
        }
        foreach ([1, 2, 3, 4] as $n) { // solo 4 partite insieme: non fa statistica
            $this->played($this->games[$n], $this->rocco);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function played(Game $g, Player $p, int $goals = 0): void
    {
        MatchPlayerStat::create(['match_id' => $g->id, 'player_id' => $p->id, 'played' => true, 'goals' => $goals]);
    }

    private function profile(): array
    {
        return $this->getJson("/api/v1/public/players/{$this->pino->id}")->assertOk()->json('data');
    }

    private function fake(?string $reply = null, ?\Throwable $error = null, bool $configured = true): object
    {
        $fake = new class($reply, $error, $configured) implements TextGenerator
        {
            public ?string $system = null;

            public ?string $prompt = null;

            public ?float $temperature = null;

            public int $calls = 0;

            public function __construct(private ?string $reply, private ?\Throwable $error, private bool $configured) {}

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function generate(string $system, string $prompt, int $maxTokens = 2000, ?float $temperature = null): string
            {
                $this->calls++;
                $this->system = $system;
                $this->prompt = $prompt;
                $this->temperature = $temperature;

                return $this->error ? throw $this->error : (string) $this->reply;
            }
        };
        $this->app->instance(TextGenerator::class, $fake);

        return $fake;
    }

    public function test_partners_are_the_teammates_with_whom_the_team_earns_most_points_with_at_least_six_matches_together(): void
    {
        $partners = $this->profile()['partners'];

        $this->assertSame(['Sara Seconda', 'Quinto Quarto'], array_map(fn ($p) => $p['player']['full_name'], $partners), 'Rocco ha solo 4 partite in comune');
        $this->assertSame([6, 5, 1, 0, 2.67], [$partners[0]['played'], $partners[0]['won'], $partners[0]['drawn'], $partners[0]['lost'], $partners[0]['points_per_match']]);
        $this->assertSame([7, 4, 1, 2, 1.86], [$partners[1]['played'], $partners[1]['won'], $partners[1]['drawn'], $partners[1]['lost'], $partners[1]['points_per_match']]);
    }

    public function test_victims_are_the_teams_he_scored_against_most(): void
    {
        $victims = $this->profile()['victims'];

        $this->assertCount(1, $victims, 'a YANKEE non ha mai segnato');
        $this->assertSame(['XRAY', 4, 4], [$victims[0]['team']['name'], $victims[0]['goals'], $victims[0]['matches']]);
    }

    public function test_milestones_point_to_the_next_round_number_of_appearances_and_goals(): void
    {
        $m = $this->profile()['milestones'];

        $this->assertSame(['current' => 8, 'next' => 10, 'missing' => 2], $m['matches']);
        $this->assertSame(['current' => 4, 'next' => 10, 'missing' => 6], $m['goals']);
    }

    public function test_current_streaks_count_from_the_last_appearance(): void
    {
        $this->assertSame(['scoring_now' => 0, 'drought_now' => 3], $this->profile()['streaks']);

        MatchPlayerStat::where('player_id', $this->pino->id)->where('match_id', $this->games[8]->id)->update(['goals' => 2]);
        MatchPlayerStat::where('player_id', $this->pino->id)->where('match_id', $this->games[7]->id)->update(['goals' => 1]);

        $this->assertSame(['scoring_now' => 2, 'drought_now' => 0], $this->profile()['streaks']);
    }

    public function test_the_ai_scout_is_saved_on_the_player_and_shown_on_his_public_page(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $fake = $this->fake("\"Pino è l'attaccante di riferimento: 8 presenze e 4 gol.\n\nUn punto fermo.\"");

        $r = $this->postJson("/api/v1/players/{$this->pino->id}/scout")->assertOk()->json('data');

        $this->assertSame('ai', $r['source']);
        $this->assertSame(config('amir.ai.gemini.model'), $r['model']);
        $this->assertNull($r['note']);
        $this->assertSame("Pino è l'attaccante di riferimento: 8 presenze e 4 gol. Un punto fermo.", $r['text'], 'senza virgolette e su un solo paragrafo');
        $this->assertSame($r['text'], $r['player']['scout_text']);

        $this->assertSame(0.5, $fake->temperature);
        $this->assertStringContainsString('Usa SOLO i dati forniti', $fake->system);
        $this->assertStringContainsString('"presenze": 8', $fake->prompt);
        $this->assertStringContainsString('"gol": 4', $fake->prompt);
        $this->assertStringContainsString('Sara Seconda', $fake->prompt, 'il compagno d\'oro è fra i fatti');
        $this->assertStringContainsString('XRAY (4 gol)', $fake->prompt);

        $scout = $this->profile()['scout'];
        $this->assertSame(['ai', $r['text']], [$scout['source'], $scout['text']]);
    }

    public function test_when_the_ai_fails_or_has_no_key_a_template_is_written_and_the_note_says_why(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->fake(error: new RuntimeException('timeout'));
        $failed = $this->postJson("/api/v1/players/{$this->pino->id}/scout")->assertOk()->json('data');
        $this->assertSame('template', $failed['source']);
        $this->assertNull($failed['model']);
        $this->assertStringContainsString("L'IA non ha risposto", $failed['note']);
        $this->assertStringContainsString('timeout', $failed['note']);
        $this->assertStringContainsString('Pino, attaccante, ha 8 presenze con AMIR e 4 gol (0,5 a partita).', $failed['text']);
        $this->assertStringContainsString('Rende al meglio in coppia con Sara Seconda.', $failed['text']);

        $fake = $this->fake(configured: false);
        $noKey = $this->postJson("/api/v1/players/{$this->pino->id}/scout")->assertOk()->json('data');
        $this->assertSame(0, $fake->calls);
        $this->assertSame('template', $noKey['source']);
        $this->assertStringContainsString('GEMINI_API_KEY', $noKey['note']);
    }

    public function test_a_player_without_appearances_gets_an_honest_template(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->fake(configured: false);
        $newcomer = Player::create(['team_id' => $this->own->id, 'first_name' => 'Nuovo', 'last_name' => 'Arrivato']);

        $r = $this->postJson("/api/v1/players/{$newcomer->id}/scout")->assertOk()->json('data');

        $this->assertStringContainsString('non ha ancora presenze registrate', $r['text']);
    }

    public function test_the_staff_can_correct_or_remove_the_scout_text(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $saved = $this->putJson("/api/v1/players/{$this->pino->id}/scout", ['text' => '  Il nostro bomber.  '])->assertOk()->json('data');
        $this->assertSame(['Il nostro bomber.', 'manual'], [$saved['scout_text'], $saved['scout_source']]);
        $this->assertSame('manual', $this->profile()['scout']['source']);

        $this->putJson("/api/v1/players/{$this->pino->id}/scout", ['text' => str_repeat('x', 701)])->assertUnprocessable();

        $cleared = $this->putJson("/api/v1/players/{$this->pino->id}/scout", ['text' => ''])->assertOk()->json('data');
        $this->assertNull($cleared['scout_text']);
        $this->assertNull($this->profile()['scout']);
    }

    public function test_writing_a_scout_needs_the_staff_login(): void
    {
        $this->postJson("/api/v1/players/{$this->pino->id}/scout")->assertUnauthorized();
        $this->putJson("/api/v1/players/{$this->pino->id}/scout", ['text' => 'x'])->assertUnauthorized();
    }

    public function test_the_dashboard_lists_the_birthdays_of_the_next_weeks_for_the_staff_only(): void
    {
        $this->pino->update(['birth_date' => '1996-10-17']);   // 17 ottobre: tra 10 giorni, compie 30 anni
        $this->sara->update(['birth_date' => '1990-10-01']);   // già passato quest'anno: tra un anno, fuori finestra
        $this->quinto->update(['birth_date' => '1985-11-20']);  // tra 44 giorni, compie 41 anni
        $this->rocco->update(['birth_date' => '1988-12-25']);   // troppo lontano

        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $b = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.birthdays');

        $this->assertSame(['Pino Primo', 'Quinto Quarto'], array_map(fn ($x) => $x['player']['full_name'], $b));
        $this->assertSame(['2026-10-17', 30, 10], [$b[0]['date'], $b[0]['turns'], $b[0]['days_left']]);
        $this->assertSame(['2026-11-20', 41, 44], [$b[1]['date'], $b[1]['turns'], $b[1]['days_left']]);

        $this->assertArrayNotHasKey('birthdays', $this->getJson('/api/v1/public/home')->json('data'), 'il compleanno è un dato personale');
    }
}
