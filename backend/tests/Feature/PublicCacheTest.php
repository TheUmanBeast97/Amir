<?php

namespace Tests\Feature;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Le risposte pubbliche si tengono nella rete di Vercel; tutto il resto no. */
class PublicCacheTest extends TestCase
{
    use RefreshDatabase;

    private function ownTeam(): Team
    {
        return Team::create(['name' => 'AMIR COSTRUZIONI', 'short_name' => 'AMIR', 'xfive_club_id' => 159, 'is_own' => true, 'format' => 8]);
    }

    public function test_public_answers_can_be_kept_by_the_edge_network_and_the_browser(): void
    {
        $this->ownTeam();

        foreach (['/api/v1/public/roster', '/api/v1/public/standings', '/api/v1/public/home'] as $url) {
            $cache = (string) $this->getJson($url)->assertOk()->headers->get('Cache-Control');

            $this->assertStringContainsString('public', $cache, $url);
            $this->assertStringContainsString('s-maxage=60', $cache, $url);
            $this->assertStringContainsString('stale-while-revalidate=3600', $cache, $url);
            $this->assertStringNotContainsString('private', $cache, $url);
        }
    }

    public function test_errors_are_never_kept(): void
    {
        $this->ownTeam();

        $cache = (string) $this->getJson('/api/v1/public/players/999999')->assertNotFound()->headers->get('Cache-Control');

        $this->assertStringNotContainsString('s-maxage', $cache);
    }

    public function test_staff_and_personal_answers_stay_private(): void
    {
        $this->ownTeam();

        $staff = (string) $this->getJson('/api/v1/players')->assertUnauthorized()->headers->get('Cache-Control');
        $personal = (string) $this->getJson('/api/v1/me/'.str_repeat('x', 64))->headers->get('Cache-Control');

        $this->assertStringNotContainsString('s-maxage', $staff);
        $this->assertStringNotContainsString('public', $staff);
        $this->assertStringNotContainsString('s-maxage', $personal);
        $this->assertStringNotContainsString('public', $personal);
    }

    public function test_images_keep_their_own_longer_rule(): void
    {
        $this->ownTeam();
        $other = Team::create(['name' => 'ALTRI', 'short_name' => 'ALT', 'xfive_club_id' => 7, 'is_own' => false, 'format' => 8]);
        DB::table('media_files')->insert([
            'path' => 'badges/test.png', 'mime' => 'image/png', 'bytes' => 4, 'data' => base64_encode('abcd'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $other->forceFill(['badge_path' => 'badges/test.png'])->save();

        $cache = (string) $this->get("/api/v1/public/badges/{$other->id}")->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('max-age=86400', $cache);
        $this->assertStringNotContainsString('max-age=30', $cache);
    }
}
