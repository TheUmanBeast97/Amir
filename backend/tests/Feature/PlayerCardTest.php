<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Game;
use App\Models\MatchPlayerStat;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** La figurina: i totali che servono al voto (gol subiti, porta inviolata) e la sagoma senza sfondo salvata una volta sola. */
class PlayerCardTest extends TestCase
{
    use RefreshDatabase;

    private Team $own;

    private Player $gigi;

    private Player $pino;

    protected function setUp(): void
    {
        parent::setUp();
        $this->own = Team::create(['name' => 'AMIR', 'xfive_club_id' => 159, 'is_own' => true]);
        $x = Team::create(['name' => 'XRAY', 'xfive_club_id' => 2]);
        $league = Competition::create(['xfive_tournament_id' => 10, 'name' => 'Lega', 'season' => '2025/2026', 'kind' => 'campionato', 'format' => 8, 'is_current' => true, 'has_own_team' => true]);

        $this->gigi = Player::create(['team_id' => $this->own->id, 'first_name' => 'Gigi', 'last_name' => 'Guanti', 'role' => 'portiere', 'is_active' => true]);
        $this->pino = Player::create(['team_id' => $this->own->id, 'first_name' => 'Pino', 'last_name' => 'Primo', 'role' => 'attaccante', 'is_active' => true]);

        // subiti: 0 1 1 2 1 0 3 0 = 8 in 8 partite, 3 porte inviolate
        $scores = [[3, 0], [2, 1], [1, 1], [0, 2], [4, 1], [1, 0], [1, 3], [2, 0]];
        foreach ($scores as $i => [$for, $against]) {
            $game = Game::create([
                'competition_id' => $league->id, 'round' => $i + 1, 'round_label' => ($i + 1).'ª giornata',
                'home_team_id' => $this->own->id, 'away_team_id' => $x->id,
                'home_score' => $for, 'away_score' => $against, 'kickoff_at' => now()->subDays(60 - $i * 7), 'status' => Game::PLAYED,
            ]);
            MatchPlayerStat::create(['match_id' => $game->id, 'player_id' => $this->gigi->id, 'played' => true]);
            if ($i < 4) { // Pino solo nelle prime quattro: subiti 4, una porta inviolata
                MatchPlayerStat::create(['match_id' => $game->id, 'player_id' => $this->pino->id, 'played' => true, 'goals' => 2]);
            }
        }
    }

    private function profile(Player $p): array
    {
        return $this->getJson("/api/v1/public/players/{$p->id}")->assertOk()->json('data');
    }

    public function test_the_profile_counts_goals_conceded_and_clean_sheets_while_he_was_on_the_pitch(): void
    {
        $t = $this->profile($this->gigi)['totals'];
        $this->assertSame(8, $t['conceded']);
        $this->assertSame(3, $t['clean_sheets']);
        $this->assertEquals(1.0, $t['conceded_per_match']);
        $this->assertEquals(1.0, $t['team_conceded_per_match'], 'la media della squadra su tutte le partite contate');

        $t = $this->profile($this->pino)['totals'];
        $this->assertSame(4, $t['conceded']);
        $this->assertSame(1, $t['clean_sheets']);
        $this->assertEquals(1.0, $t['team_conceded_per_match'], 'il riferimento è lo stesso per tutti');
    }

    public function test_the_profile_carries_the_card_rating_with_its_breakdown(): void
    {
        $card = $this->profile($this->gigi)['card'];

        $this->assertSame('portiere', $card['role']);
        $this->assertGreaterThanOrEqual(80, $card['ovr']);
        $this->assertLessThanOrEqual(99, $card['ovr']);
        $this->assertContains($card['tier'], ['bronzo', 'argento', 'oro', 'platino', 'fuoco']);
        $this->assertContains('conceded', array_column($card['parts'], 'key'));
        $this->assertNotContains('goals', array_column($card['parts'], 'key'));

        $this->assertSame('attaccante', $this->profile($this->pino)['card']['role']);
    }

    public function test_the_staff_saves_the_cutout_once_and_everyone_sees_it_from_then_on(): void
    {
        $this->assertNull($this->profile($this->gigi)['player']['cutout_url']);

        Sanctum::actingAs(User::factory()->create());
        $png = UploadedFile::fake()->createWithContent('sagoma.png', $this->png());

        $this->post("/api/v1/players/{$this->gigi->id}/cutout", ['file' => $png], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.id', $this->gigi->id);

        $url = $this->profile($this->gigi)['player']['cutout_url'];
        $this->assertStringContainsString("/public/players/{$this->gigi->id}/cutout", $url);
        $this->assertSame(1, DB::table('media_files')->where('path', "media/cutouts/{$this->gigi->id}.png")->count());

        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/public/players/{$this->gigi->id}/cutout")->assertOk()->assertHeader('Content-Type', 'image/png');

        // caricarla di nuovo la sostituisce, senza doppioni
        Sanctum::actingAs(User::first());
        $this->post("/api/v1/players/{$this->gigi->id}/cutout", ['file' => UploadedFile::fake()->createWithContent('b.png', $this->png())], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, DB::table('media_files')->where('path', 'like', 'media/cutouts/%')->count());
    }

    public function test_only_the_staff_can_save_a_cutout_and_only_a_png(): void
    {
        $this->post("/api/v1/players/{$this->gigi->id}/cutout", ['file' => UploadedFile::fake()->createWithContent('a.png', $this->png())], ['Accept' => 'application/json'])
            ->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->post("/api/v1/players/{$this->gigi->id}/cutout", ['file' => UploadedFile::fake()->createWithContent('a.txt', 'ciao')], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->get("/api/v1/public/players/{$this->gigi->id}/cutout")->assertNotFound();
    }

    /** Un PNG vero di 1x1 pixel. */
    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }
}
