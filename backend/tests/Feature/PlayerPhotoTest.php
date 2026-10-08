<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Services\MediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** La foto del giocatore nell'area staff (caricata, tolta, riletta da XFive) e la rilettura di profili e foto della rosa. */
class PlayerPhotoTest extends TestCase
{
    use RefreshDatabase;

    private Team $own;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-09 10:00:00');
        config(['amir.xfive.throttle_ms' => 0, 'amir.sync.inline' => true, 'amir.sync.budget' => 40]);
        $this->own = Team::create(['name' => 'AMIR COSTRUZIONI', 'short_name' => 'AMIR', 'xfive_club_id' => 159, 'is_own' => true, 'format' => 8]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function png(string $seed = 'a'): string
    {
        return "\x89PNG\r\n\x1a\n".str_repeat($seed, 800);
    }

    private function mario(): Player
    {
        return Player::create(['team_id' => $this->own->id, 'first_name' => 'Mario', 'last_name' => 'Demo', 'is_active' => true]);
    }

    /** Il contenuto della foto sul CDN finto: cambiarlo simula una foto nuova su XFive. */
    private string $cdnPhoto = 'a';

    private bool $faked = false;

    /** XFive finto: la ricerca trova Mario (profilo 377 con foto sul CDN), il profilo elenca il nostro club, il CDN risponde con un PNG. */
    private function fakeProfile(string $photo = 'a'): void
    {
        $this->cdnPhoto = $photo;
        if ($this->faked) {
            return; // le risposte finte si registrano una volta sola: la prima registrata vince
        }
        $this->faked = true;

        Http::fake([
            '*/finder.php' => Http::response([[
                'url' => 'https://www.xfivesport.it/it/player-info/377/mario-demo/',
                'avatar' => '<img class="avatar" src="https://cdn.enjore.com/wl/x/img/player/q/377-abc.png" />',
                'labelt' => 'Mario Demo',
            ]]),
            '*/player-info/377/*' => Http::response(
                '<dl class="dl-horizontal"><dd>30</dd><dd>Italia</dd></dl>'
                .'<div id="wl-playedtournament-container">'
                .'<div class="wl-tournamentinfo-container"><h4><a href="/it/team-h/159/amir/">AMIR COSTRUZIONI</a></h4><span>Difensore</span></div>'
                .'<div class="wl-tournamentinfo-container"><h4><a href="/it/tournament/187/x/">CITTADELLA</a></h4><span>2026/2027 - Calcio a 8</span></div>'
                .'</div>',
            ),
            'cdn.enjore.com/*' => fn () => Http::response($this->png($this->cdnPhoto), 200, ['Content-Type' => 'image/png']),
        ]);
    }

    private function jpeg(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('mario.jpg', "\xFF\xD8\xFF\xE0".str_repeat('j', 600));
    }

    public function test_a_photo_uploaded_by_the_staff_is_theirs_and_xfive_does_not_replace_it(): void
    {
        $this->staff();
        $p = $this->mario();
        $p->update(['cutout_path' => "media/cutouts/{$p->id}.png"]);
        app(MediaStore::class)->put($p->cutout_path, $this->png('c'), 'image/png');

        $r = $this->post("/api/v1/players/{$p->id}/photo", ['file' => $this->jpeg()], ['Accept' => 'application/json'])->assertOk()->json('data');

        $this->assertSame('upload', $r['photo_source']);
        $this->assertSame(url("/api/v1/public/players/{$p->id}/photo").'?v='.now()->getTimestamp(), $r['photo_url'], 'la versione nell\'indirizzo cambia a ogni foto nuova');
        $this->assertNull($r['cutout_url'], 'la sagoma era fatta dalla foto vecchia');
        $this->get("/api/v1/public/players/{$p->id}/photo")->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // la rilettura da XFive aggiorna il profilo ma lascia la foto caricata
        $this->fakeProfile();
        $s = $this->postJson("/api/v1/players/{$p->id}/sync")->assertOk()->json('data');
        $this->assertSame(['matched', false], [$s['profile'], $s['photo']]);
        $this->assertSame(['upload', 'Italia', 'difensore', 377], [$s['player']['photo_source'], $s['player']['nationality'], $s['player']['role'], $p->refresh()->xfive_person_id]);

        // ...a meno che non si chieda apposta la foto di XFive
        Carbon::setTestNow(now()->addMinute());
        $s = $this->postJson("/api/v1/players/{$p->id}/sync", ['photo' => true])->assertOk()->json('data');
        $this->assertSame(['matched', true, 'xfive'], [$s['profile'], $s['photo'], $s['player']['photo_source']]);
        $this->assertStringEndsWith('?v='.now()->getTimestamp(), $s['player']['photo_url']);
        $this->get("/api/v1/public/players/{$p->id}/photo")->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_a_removed_photo_stays_away_until_the_staff_asks_xfive_again(): void
    {
        $this->staff();
        $this->fakeProfile();
        $p = $this->mario();
        $this->postJson("/api/v1/players/{$p->id}/sync")->assertOk()->assertJsonPath('data.photo', true);

        $r = $this->deleteJson("/api/v1/players/{$p->id}/photo")->assertOk()->json('data');
        $this->assertSame(['none', null, null], [$r['photo_source'], $r['photo_url'], $r['cutout_url']]);
        $this->get("/api/v1/public/players/{$p->id}/photo")->assertNotFound();

        // né la rilettura del giocatore, né «stemmi e foto», né la rosa la riportano da sole
        $this->postJson("/api/v1/players/{$p->id}/sync")->assertOk()->assertJsonPath('data.photo', false);
        $this->postJson('/api/v1/sync/xfive', ['scope' => 'media'])->assertOk()->assertJsonPath('data.stats.photos', 0);
        $this->postJson('/api/v1/sync/xfive', ['scope' => 'roster'])->assertOk()->assertJsonPath('data.stats.photos', 0);
        $this->assertSame('none', $p->refresh()->photo_source);

        $this->postJson("/api/v1/players/{$p->id}/sync", ['photo' => true])->assertOk()->assertJsonPath('data.photo', true)->assertJsonPath('data.player.photo_source', 'xfive');
    }

    public function test_the_roster_update_rereads_every_active_player_and_replaces_photos_that_changed(): void
    {
        $this->staff();
        $this->fakeProfile('a');
        $p = $this->mario();
        $former = Player::create(['team_id' => $this->own->id, 'first_name' => 'Gianni', 'last_name' => 'Ex', 'is_active' => false]);
        $this->postJson("/api/v1/players/{$p->id}/sync")->assertOk();
        $p->update(['cutout_path' => "media/cutouts/{$p->id}.png"]);
        $firstVersion = $p->refresh()->photo_updated_at;

        // stessa foto: nulla cambia, la sagoma resta
        $this->postJson('/api/v1/sync/xfive', ['scope' => 'roster'])->assertOk()
            ->assertJsonPath('data.stats.players', 1)->assertJsonPath('data.stats.photos', 0)->assertJsonPath('data.stats.remaining', 0);
        $this->assertNotNull($p->refresh()->cutout_path);

        // su XFive la foto è cambiata: si sostituisce, la versione avanza e la sagoma si butta
        Carbon::setTestNow(now()->addDay());
        $this->fakeProfile('b');
        $this->postJson('/api/v1/sync/xfive', ['scope' => 'roster'])->assertOk()
            ->assertJsonPath('data.stats.players', 1)->assertJsonPath('data.stats.photos', 1);

        $p->refresh();
        $this->assertTrue($p->photo_updated_at->greaterThan($firstVersion));
        $this->assertNull($p->cutout_path);
        $this->assertSame($this->png('b'), $this->get("/api/v1/public/players/{$p->id}/photo")->assertOk()->getContent());
        $this->assertNull($former->refresh()->xfive_synced_at, 'gli ex giocatori non si rileggono');
    }

    public function test_the_nightly_stats_update_rereads_only_who_has_not_been_read_for_a_week(): void
    {
        $this->staff();
        $this->fakeProfile();
        $recent = $this->mario();
        $recent->update(['xfive_synced_at' => now()->subDays(2), 'xfive_person_id' => 377]);
        $stale = Player::create(['team_id' => $this->own->id, 'first_name' => 'Luigi', 'last_name' => 'Bianchi', 'is_active' => true, 'xfive_synced_at' => now()->subDays(8)]);

        $this->postJson('/api/v1/sync/xfive', ['scope' => 'stats'])->assertOk()
            ->assertJsonPath('data.stats.refreshed', 1)->assertJsonPath('data.stats.remaining', 0);

        $this->assertSame('2026-10-07', $recent->refresh()->xfive_synced_at->toDateString(), 'letto due giorni fa: si aspetta');
        $this->assertSame('2026-10-09', $stale->refresh()->xfive_synced_at->toDateString(), 'anche se su XFive non si trova, la lettura si segna: tocca a un altro domani');
        Http::assertSentCount(1); // solo la ricerca di Luigi
    }

    public function test_photo_actions_need_the_staff_login(): void
    {
        $p = $this->mario();

        $this->post("/api/v1/players/{$p->id}/photo", ['file' => $this->jpeg()], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->deleteJson("/api/v1/players/{$p->id}/photo")->assertUnauthorized();
        $this->postJson("/api/v1/players/{$p->id}/sync")->assertUnauthorized();

        $this->staff();
        $this->post("/api/v1/players/{$p->id}/photo", ['file' => UploadedFile::fake()->createWithContent('x.txt', 'ciao')], ['Accept' => 'application/json'])->assertUnprocessable();
    }
}
