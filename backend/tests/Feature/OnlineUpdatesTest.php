<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SyncRun;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Gli aggiornamenti da XFive sul server: a pezzi, entro un tempo massimo, dal sito o dalla pianificazione. */
class OnlineUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private Team $own;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local'); // mai i file veri del gestionale
        config(['amir.xfive.throttle_ms' => 0, 'amir.sync.inline' => true, 'amir.sync.budget' => 40]);
        $this->own = Team::create([
            'name' => 'AMIR COSTRUZIONI', 'short_name' => 'AMIR', 'xfive_club_id' => 159, 'is_own' => true, 'format' => 8,
            'badge_url' => 'https://cdn.enjore.com/wl/x/img/team/badge/s/159abc.png',
        ]);
    }

    private function png(): string
    {
        return "\x89PNG\r\n\x1a\n".str_repeat('x', 800);
    }

    private function fakeCdnAndFinder(): void
    {
        Http::fake([
            '*/finder.php' => Http::response([[
                'url' => 'https://www.xfivesport.it/it/player-info/377/mario-demo/',
                'avatar' => '<img class="avatar" src="https://cdn.enjore.com/wl/x/img/player/q/377-abc.jpg" />',
                'labelt' => 'Mario Demo',
            ]]),
            'cdn.enjore.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
    }

    private function playerWithProfile(): Player
    {
        return Player::create(['team_id' => $this->own->id, 'first_name' => 'Mario', 'last_name' => 'Demo', 'xfive_person_id' => 377]);
    }

    public function test_the_media_update_fills_missing_badges_and_photos_and_answers_with_the_result(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->fakeCdnAndFinder();
        $player = $this->playerWithProfile();

        // sul server la risposta contiene già l'esito (200), non una corsa «in corso» (202)
        $this->postJson('/api/v1/sync/xfive', ['scope' => 'media'])
            ->assertOk()
            ->assertJsonPath('data.scope', 'media')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.stats.badges', 1)
            ->assertJsonPath('data.stats.photos', 1)
            ->assertJsonPath('data.stats.remaining', 0);

        $this->assertNotNull($this->own->refresh()->badge_path);
        $this->assertNotNull($player->refresh()->photo_path);
        $this->get("/api/v1/public/badges/{$this->own->id}")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get("/api/v1/public/players/{$player->id}/photo")->assertOk();

        // la seconda volta non c'è più nulla da scaricare
        Http::fake();
        $this->postJson('/api/v1/sync/xfive', ['scope' => 'media'])->assertOk()->assertJsonPath('data.stats.badges', 0)->assertJsonPath('data.stats.photos', 0);
        Http::assertNothingSent();
    }

    public function test_after_a_restore_the_paths_are_there_but_the_images_are_not_and_they_are_downloaded_again(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->fakeCdnAndFinder();

        // come dopo un ripristino: i percorsi ci sono, le immagini no (i backup non le contengono)
        $this->own->update(['badge_path' => 'media/badges/1.png']);
        $active = $this->playerWithProfile();
        $active->update(['photo_path' => 'media/players/1.png']);
        // un ex giocatore: ha l'indirizzo della foto su XFive, si scarica direttamente senza cercarlo
        $former = Player::create([
            'team_id' => $this->own->id, 'first_name' => 'Gianni', 'last_name' => 'Ex', 'is_active' => false,
            'photo_url' => 'https://cdn.enjore.com/wl/x/img/player/q/55-xyz.jpg', 'photo_path' => 'media/players/2.jpg',
        ]);

        $this->postJson('/api/v1/sync/xfive', ['scope' => 'media'])
            ->assertOk()
            ->assertJsonPath('data.stats.badges', 1)
            ->assertJsonPath('data.stats.photos', 2)
            ->assertJsonPath('data.stats.remaining', 0);

        $this->get("/api/v1/public/badges/{$this->own->id}")->assertOk();
        $this->get("/api/v1/public/players/{$active->id}/photo")->assertOk();
        $this->get("/api/v1/public/players/{$former->id}/photo")->assertOk();
        // un ex giocatore non si cerca per nome: una sola ricerca, quella dell'attivo
        Http::assertSentCount(1 + 1 + 2); // ricerca + stemma + due foto
    }

    public function test_an_update_stops_at_the_time_limit_and_says_how_much_is_left(): void
    {
        Sanctum::actingAs(User::factory()->create());
        config(['amir.sync.budget' => 0]);
        $this->fakeCdnAndFinder();
        $this->playerWithProfile();

        $this->postJson('/api/v1/sync/xfive', ['scope' => 'media'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.stats.badges', 0)
            ->assertJsonPath('data.stats.photos', 0)
            ->assertJsonPath('data.stats.remaining', 2);

        Http::assertNothingSent();
    }

    public function test_a_player_without_a_real_photo_on_xfive_is_not_retried_every_time(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Http::fake([
            '*/finder.php' => Http::response([[
                'url' => 'https://www.xfivesport.it/it/player-info/377/mario-demo/',
                'avatar' => '<img src="https://cdn.enjore.com/wl/x/img/player/q/ph_player.png" />',
                'labelt' => 'Mario Demo',
            ]]),
            'cdn.enjore.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
        $this->own->update(['badge_url' => null]);
        $this->playerWithProfile();

        $this->postJson('/api/v1/sync/xfive', ['scope' => 'media'])->assertOk()->assertJsonPath('data.stats.photos', 0)->assertJsonPath('data.stats.remaining', 0);
        Http::assertSentCount(1); // la ricerca; nessun download del segnaposto

        $this->postJson('/api/v1/sync/xfive', ['scope' => 'media'])->assertOk();
        Http::assertSentCount(1);
    }

    public function test_details_and_stats_updates_run_even_when_there_is_nothing_to_do(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Http::fake();

        $this->postJson('/api/v1/sync/xfive', ['scope' => 'details'])->assertOk()->assertJsonPath('data.status', 'ok')->assertJsonPath('data.stats.remaining', 0);
        $this->postJson('/api/v1/sync/xfive', ['scope' => 'stats'])->assertOk()->assertJsonPath('data.status', 'ok')->assertJsonPath('data.stats.remaining', 0);
    }

    public function test_a_run_left_in_progress_by_an_interrupted_request_does_not_block_new_ones(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Http::fake();
        $stale = SyncRun::create(['scope' => 'details', 'status' => 'running', 'started_at' => now()->subMinutes(5), 'stats' => []]);

        $this->postJson('/api/v1/sync/xfive', ['scope' => 'details'])->assertOk()->assertJsonPath('data.status', 'ok');
        $this->assertNotSame($stale->id, SyncRun::latest('id')->first()->id);
    }

    public function test_the_scheduled_updates_need_the_secret_and_only_exist_when_it_is_set(): void
    {
        Http::fake();

        // senza CRON_SECRET le rotte non esistono
        config(['amir.cron_secret' => null]);
        $this->getJson('/api/v1/cron/media')->assertNotFound();

        config(['amir.cron_secret' => 's3greto-di-prova']);
        $this->getJson('/api/v1/cron/media')->assertUnauthorized();
        $this->getJson('/api/v1/cron/media', ['Authorization' => 'Bearer sbagliato'])->assertUnauthorized();
        $this->getJson('/api/v1/cron/sconosciuto', ['Authorization' => 'Bearer s3greto-di-prova'])->assertNotFound();

        $this->getJson('/api/v1/cron/details', ['Authorization' => 'Bearer s3greto-di-prova'])
            ->assertOk()
            ->assertJsonPath('data.scope', 'details')
            ->assertJsonPath('data.status', 'ok');

        $this->assertSame(1, SyncRun::where('scope', 'details')->count(), 'resta traccia nello storico degli aggiornamenti');
    }
}
