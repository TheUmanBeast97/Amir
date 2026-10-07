<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Game;
use App\Models\MatchPlayerStat;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\GeminiTextGenerator;
use App\Services\Ai\TextGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/** Didascalie: l'IA riceve fatti veri e, se non risponde, si ripiega sui modelli di testo. Nessuna chiamata di rete. */
class CaptionTest extends TestCase
{
    use RefreshDatabase;

    private Team $own;

    private Team $rival;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
        $this->own = Team::create(['name' => 'AMIR COSTRUZIONI', 'short_name' => 'AMIR', 'xfive_club_id' => 159, 'is_own' => true, 'format' => 8]);
        $this->rival = Team::create(['name' => 'VALONS', 'xfive_club_id' => 432]);
    }

    private function fake(?string $reply = null, ?\Throwable $error = null, bool $configured = true): object
    {
        $fake = new class($reply, $error, $configured) implements TextGenerator
        {
            public ?string $system = null;

            public ?string $prompt = null;

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

                return $this->error ? throw $this->error : (string) $this->reply;
            }
        };
        $this->app->instance(TextGenerator::class, $fake);

        return $fake;
    }

    private function competition(): Competition
    {
        return Competition::create([
            'xfive_tournament_id' => 187, 'name' => 'Cittadella', 'season' => '2026/2027',
            'kind' => 'campionato', 'format' => 8, 'is_current' => true, 'has_own_team' => true,
        ]);
    }

    private function upcoming(): Game
    {
        return Game::create([
            'competition_id' => $this->competition()->id, 'round' => 1, 'round_label' => '1ª giornata',
            'home_team_id' => $this->own->id, 'away_team_id' => $this->rival->id,
            'kickoff_at' => '2026-10-15 20:00:00', 'venue' => '100GRIGIO - CAMPO 4', 'status' => Game::SCHEDULED, 'our_kit' => 'red',
        ]);
    }

    public function test_the_ai_writes_the_caption_from_the_real_facts_of_a_played_match(): void
    {
        $game = $this->upcoming();
        $game->update(['status' => Game::PLAYED, 'home_score' => 3, 'away_score' => 1]);
        $andrea = Player::create(['team_id' => $this->own->id, 'first_name' => 'Andrea', 'last_name' => 'Blu', 'nickname' => 'Bomber']);
        MatchPlayerStat::create(['match_id' => $game->id, 'player_id' => $andrea->id, 'played' => true, 'goals' => 2]);
        $game->update(['man_of_the_match_id' => $andrea->id]);

        $fake = $this->fake("\"Che serata! 🔴 #AmirCostruzioni #XFive\n\n\n\nGrazie a tutti\"");

        $r = $this->postJson("/api/v1/matches/{$game->id}/caption", ['tone' => 'ironico', 'kind' => 'risultato', 'notes' => 'ringrazia il pubblico'])
            ->assertOk()->json('data');

        $this->assertSame('ai', $r['source']);
        $this->assertSame(config('amir.ai.gemini.model'), $r['model']);
        $this->assertNull($r['note']);
        $this->assertSame("Che serata! 🔴 #AmirCostruzioni #XFive\n\nGrazie a tutti", $r['text'], 'senza virgolette attorno né righe vuote in eccesso');

        $this->assertSame(1, $fake->calls);
        $this->assertStringContainsString('Ironico', $fake->system);
        $this->assertStringContainsString('Non inventare', $fake->system);
        $this->assertStringContainsString('AMIR COSTRUZIONI 3 - 1 VALONS', $fake->prompt);
        $this->assertStringContainsString('vittoria', $fake->prompt);
        $this->assertStringContainsString('Andrea Blu', $fake->prompt);
        $this->assertStringContainsString('Bomber', $fake->prompt);
        $this->assertStringContainsString('ringrazia il pubblico', $fake->prompt);
        $this->assertStringContainsString('risultato finale', $fake->prompt);
    }

    public function test_the_prompt_for_an_upcoming_match_has_date_venue_kit_and_no_result(): void
    {
        $game = $this->upcoming();
        $fake = $this->fake('Si parte! #XFive');

        $this->postJson("/api/v1/matches/{$game->id}/caption", ['tone' => 'epico', 'kind' => 'matchday'])->assertOk();

        $this->assertStringContainsString('giovedì 15 ottobre alle 20:00', $fake->prompt);
        $this->assertStringContainsString('100GRIGIO - CAMPO 4', $fake->prompt);
        $this->assertStringContainsString('rossa', $fake->prompt);
        $this->assertStringNotContainsString('"risultato"', $fake->prompt);
        $this->assertStringContainsString('"partita_giocata": false', $fake->prompt);
    }

    public function test_head_to_head_history_is_given_to_the_ai(): void
    {
        $old = Competition::create(['xfive_tournament_id' => 99, 'name' => 'Vecchio', 'season' => '2025/2026', 'kind' => 'campionato', 'format' => 8, 'has_own_team' => true]);
        Game::create([
            'competition_id' => $old->id, 'home_team_id' => $this->own->id, 'away_team_id' => $this->rival->id,
            'kickoff_at' => '2026-01-10 21:00:00', 'home_score' => 4, 'away_score' => 2, 'status' => Game::PLAYED,
        ]);
        $game = $this->upcoming();
        $fake = $this->fake('Testo');

        $this->postJson("/api/v1/matches/{$game->id}/caption", ['tone' => 'sobrio', 'kind' => 'precedenti'])->assertOk();

        $this->assertStringContainsString('precedenti_contro_di_loro', $fake->prompt);
        $this->assertStringContainsString('"vittorie": 1', $fake->prompt);
    }

    public function test_when_the_ai_fails_a_text_template_is_used_and_the_response_says_so(): void
    {
        $game = $this->upcoming();
        $this->fake(error: new RuntimeException('timeout'));

        $r = $this->postJson("/api/v1/matches/{$game->id}/caption", ['tone' => 'epico'])->assertOk()->json('data');

        $this->assertSame('template', $r['source']);
        $this->assertNull($r['model']);
        $this->assertStringContainsString("L'IA non ha risposto", $r['note']);
        $this->assertStringContainsString('timeout', $r['note'], 'il motivo vero arriva fino allo staff');
        $this->assertStringContainsString('VALONS', $r['text']);
    }

    public function test_without_an_api_key_nothing_is_sent_and_the_template_explains_how_to_enable_the_ai(): void
    {
        config(['amir.ai.gemini.api_key' => null]);
        $this->app->singleton(TextGenerator::class, GeminiTextGenerator::class);
        $game = $this->upcoming();

        $this->assertFalse(app(TextGenerator::class)->isConfigured());

        $r = $this->postJson("/api/v1/matches/{$game->id}/caption", ['tone' => 'sobrio'])->assertOk()->json('data');

        $this->assertSame('template', $r['source']);
        $this->assertStringContainsString('GEMINI_API_KEY', $r['note']);

        config(['amir.ai.gemini.api_key' => 'sk-test']);
        $this->assertTrue((new GeminiTextGenerator)->isConfigured());
    }

    public function test_unknown_tones_and_kinds_are_rejected(): void
    {
        $game = $this->upcoming();
        $fake = $this->fake('x');

        $this->postJson("/api/v1/matches/{$game->id}/caption", ['tone' => 'sarcastico'])->assertUnprocessable();
        $this->postJson("/api/v1/matches/{$game->id}/caption", ['tone' => 'epico', 'kind' => 'volantino'])->assertUnprocessable();
        $this->postJson("/api/v1/matches/{$game->id}/caption", ['tone' => 'epico', 'notes' => str_repeat('x', 401)])->assertUnprocessable();
        $this->assertSame(0, $fake->calls);
    }
}
