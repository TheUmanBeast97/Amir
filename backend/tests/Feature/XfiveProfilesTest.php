<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Player;
use App\Models\PlayerCompetitionStat;
use App\Models\Team;
use App\Services\Xfive\ImageDownloader;
use App\Services\Xfive\PlayerInfoParser;
use App\Services\Xfive\PlayerProfileSyncer;
use App\Services\Xfive\StatsTableParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Loghi, profili e statistiche dei giocatori: pagine di esempio con la struttura di XFive e nomi inventati. */
class XfiveProfilesTest extends TestCase
{
    use RefreshDatabase;

    private Team $own;

    protected function setUp(): void
    {
        parent::setUp();
        config(['amir.xfive.throttle_ms' => 0]);
        Storage::fake('local');
        $this->own = Team::create(['name' => 'AMIR COSTRUZIONI', 'short_name' => 'AMIR', 'xfive_club_id' => 159, 'is_own' => true, 'format' => 8]);
    }

    // ---------- helper per costruire le pagine ----------

    private function png(int $size = 800): string
    {
        return "\x89PNG\r\n\x1a\n".str_repeat('x', $size);
    }

    /** @param array<int, array{id:int,name:string,role?:string,tournaments?:array<int,array{id:int,name:string,season:string,format:int}>}> $clubs */
    private function infoPage(array $clubs, int $age = 24, string $nationality = 'Italia'): string
    {
        $html = '<html><body><div class="wl-playername"><h3>Mario Demo</h3></div>'
            .'<dl class="dl-horizontal"><dt>Età:</dt><dd>'.$age.'</dd><dt>Nazionalità:</dt><dd>'.$nationality.' <img src="x.gif" /></dd></dl>'
            .'<div id="wl-playedtournament-container"><h4>Squadre in cui ha giocato</h4><div class="row"><div class="col-xs-12"><div class="row">';

        foreach ($clubs as $club) {
            $html .= '<div class="col-xs-24"><div class="wl-tournamentflyer-container"><a href="https://www.xfivesport.it/it/team-h/'.$club['id'].'/club/"><img src="x.png"/></a></div>'
                .'<div class="wl-tournamentinfo-container"><h4><a href="https://www.xfivesport.it/it/team-h/'.$club['id'].'/club/">'.$club['name'].'</a></h4><span>'.($club['role'] ?? '').'</span></div></div>';

            foreach ($club['tournaments'] ?? [] as $t) {
                $html .= '<div class="col-xs-24 playedTournament"><div class="wl-tournamentflyer-container"><a href="#"><img src="x.png"/></a></div>'
                    .'<div class="wl-tournamentinfo-container"><h4><a href="https://www.xfivesport.it/it/tournament/'.$t['id'].'/t/">'.$t['name'].'</a></h4>'
                    .'<span>'.$t['season'].' - Calcio a '.$t['format'].'</span></div></div>';
            }
        }

        return $html.'</div></div></div></div></body></html>';
    }

    /** @param array<int, array{name:string, team:string, values:array<int,int>}> $rows */
    private function statsTable(array $rows, array $columns): string
    {
        $left = '<div class="left-table"><div class="tables-header tables-row"><div class="col-pos">#</div><div class="col-name">Giocatore</div></div>';
        $right = '<div class="right-table"><div><div class="tables-header tables-row">';
        foreach ($columns as $c) {
            $right .= '<div class="col-data text-center" title="'.$c.'">'.substr($c, 0, 1).'</div>';
        }
        $right .= '</div>';

        foreach ($rows as $i => $r) {
            $left .= '<div class="tables-body tables-row"><div class="col-pos tables-pos"><small>'.($i + 1).'</small></div>'
                .'<div class="col-name tables-main"><img class="img-circle" src="https://cdn.enjore.com/wl/x/img/player/q/1-abc.png">'
                .'<div class="participant-name">'.$r['name'].'<small>'.$r['team'].'</small></div></div></div>';
            $right .= '<div class="tables-body tables-row">';
            foreach ($r['values'] as $v) {
                $right .= '<div class="col-data val-general text-center"><small>'.$v.'</small></div>';
            }
            $right .= '</div>';
        }

        return '<div class="tables-container">'.$left.'</div>'.$right.'</div></div></div>';
    }

    private function player(string $first = 'Mario', string $last = 'Demo', array $extra = []): Player
    {
        return Player::create(['team_id' => $this->own->id, 'first_name' => $first, 'last_name' => $last] + $extra);
    }

    private function finderItem(int $id, string $label, string $avatar = 'https://cdn.enjore.com/wl/x/img/player/q/379-abc.jpg'): array
    {
        return [
            'url' => "https://www.xfivesport.it/it/player-info/{$id}/".str_replace(' ', '-', strtolower($label)).'/',
            'avatar' => '<img class="avatar" src="'.$avatar.'" />',
            'a' => "<a>{$label}</a>",
            'labelt' => $label,
        ];
    }

    // ---------- parser ----------

    public function test_the_player_info_parser_reads_age_nationality_and_career_per_club(): void
    {
        $html = $this->infoPage([
            ['id' => 28, 'name' => 'Altro Club', 'role' => 'Difensore', 'tournaments' => [['id' => 6, 'name' => 'Torneo A', 'season' => '2019/2020', 'format' => 8]]],
            ['id' => 159, 'name' => 'Amir Costruzioni', 'role' => 'Portiere', 'tournaments' => [
                ['id' => 144, 'name' => 'Uispic Premier', 'season' => '2025/2026', 'format' => 7],
                ['id' => 139, 'name' => 'Serie A [alessandria]', 'season' => '2025/2026', 'format' => 8],
            ]],
        ]);

        $page = (new PlayerInfoParser)->parse($html);

        $this->assertSame(24, $page['age']);
        $this->assertSame('Italia', $page['nationality']);
        $this->assertCount(2, $page['clubs']);

        $amir = collect($page['clubs'])->firstWhere('club_id', 159);
        $this->assertSame('Portiere', $amir['role']);
        $this->assertSame([144, 139], array_column($amir['tournaments'], 'id'));
        $this->assertSame('2025/2026', $amir['tournaments'][0]['season']);
        $this->assertSame(7, $amir['tournaments'][0]['format']);
    }

    public function test_the_player_info_parser_survives_a_windows_1252_page(): void
    {
        $html = mb_convert_encoding($this->infoPage([['id' => 159, 'name' => 'Amir', 'role' => 'Portiere']]), 'Windows-1252', 'UTF-8');

        $page = (new PlayerInfoParser)->parse($html);

        $this->assertSame(24, $page['age']);
        $this->assertSame('Italia', $page['nationality']);
    }

    public function test_the_stats_table_parser_aligns_names_teams_and_values(): void
    {
        $html = $this->statsTable([
            ['name' => 'Demo M.', 'team' => 'AMIR COSTRUZIONI', 'values' => [5, 1]],
            ['name' => 'Rossi L.', 'team' => 'ALTRA SQUADRA', 'values' => [3, 0]],
        ], ['Ammonizioni', 'Espulsioni']);

        $table = (new StatsTableParser)->parse($html);

        $this->assertSame(['Ammonizioni', 'Espulsioni'], $table['columns']);
        $this->assertCount(2, $table['rows']);
        $this->assertSame('Demo M.', $table['rows'][0]['name']);
        $this->assertSame('AMIR COSTRUZIONI', $table['rows'][0]['team']);
        $this->assertSame([5, 1], $table['rows'][0]['values']);
        $this->assertSame(2, $table['rows'][1]['position']);
        $this->assertSame([], (new StatsTableParser)->parse('')['rows']);
    }

    // ---------- loghi e foto ----------

    public function test_a_badge_is_downloaded_in_the_largest_size_and_stored(): void
    {
        Http::fake([
            'cdn.enjore.com/*/badge/b/*' => Http::response($this->png(900), 200, ['Content-Type' => 'image/png']),
            'cdn.enjore.com/*/badge/s/*' => Http::response($this->png(300), 200, ['Content-Type' => 'image/png']),
        ]);

        $path = app(ImageDownloader::class)->store('https://cdn.enjore.com/wl/x/img/team/badge/s/159abc.png', 'badge', 7);

        $this->assertSame('media/badges/7.png', $path);
        $stored = app(\App\Services\MediaStore::class)->get($path);
        $this->assertNotNull($stored, 'salvata nel database');
        $this->assertSame('image/png', $stored['mime']);
        $this->assertGreaterThan(900, strlen($stored['body']));
    }

    public function test_the_badge_falls_back_to_the_small_size(): void
    {
        Http::fake([
            'cdn.enjore.com/*/badge/b/*' => Http::response('<Error/>', 404, ['Content-Type' => 'application/xml']),
            'cdn.enjore.com/*/badge/s/*' => Http::response($this->png(700), 200, ['Content-Type' => 'image/png']),
        ]);

        $path = app(ImageDownloader::class)->store('https://cdn.enjore.com/wl/x/img/team/badge/s/159abc.png', 'badge', 8);

        $this->assertSame('media/badges/8.png', $path);
    }

    public function test_placeholders_and_foreign_hosts_are_never_downloaded(): void
    {
        Http::fake();
        $images = app(ImageDownloader::class);

        $this->assertNull($images->store('https://cdn.enjore.com/wl/x/img/team/badge/s/ph_team5.png', 'badge', 1));
        $this->assertNull($images->store('https://evil.example/img/team/badge/s/x.png', 'badge', 2));
        $this->assertNull($images->store('', 'badge', 3));

        Http::assertNothingSent();
    }

    public function test_the_badge_is_served_by_our_api_and_the_team_json_points_to_it(): void
    {
        app(\App\Services\MediaStore::class)->put('media/badges/'.$this->own->id.'.png', $this->png(), 'image/png');
        $this->own->update(['badge_path' => 'media/badges/'.$this->own->id.'.png', 'badge_url' => 'https://cdn.enjore.com/old.png']);

        $response = $this->get("/api/v1/public/badges/{$this->own->id}")->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age', $response->headers->get('Cache-Control'));
        $this->assertNotNull($response->headers->get('ETag'));

        // il browser che ha già l'immagine riceve «non cambiata»
        $this->get("/api/v1/public/badges/{$this->own->id}", ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);

        $this->assertStringEndsWith("/api/v1/public/badges/{$this->own->id}", \App\Support\Present::team($this->own->refresh())['badge_url']);

        $other = Team::create(['name' => 'SENZA STEMMA', 'xfive_club_id' => 5]);
        $this->get("/api/v1/public/badges/{$other->id}")->assertNotFound();
    }

    // ---------- abbinamento ai profili ----------

    private function fakeProfiles(array $finder, array $pages, array $leagueTables = []): void
    {
        Http::fake([
            '*/finder.php' => Http::response($finder),
            '*/it/player-info/*' => function ($request) use ($pages) {
                preg_match('#player-info/(\d+)/#', $request->url(), $m);

                return Http::response($pages[(int) $m[1]] ?? '<html></html>');
            },
            '*/league.php' => function ($request) use ($leagueTables) {
                return Http::response(['html' => $leagueTables[$request['type']] ?? '']);
            },
            'cdn.enjore.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
    }

    public function test_a_player_is_matched_by_name_club_and_the_profile_is_saved(): void
    {
        $p = $this->player(extra: ['birth_date' => now()->subYears(24)->subDays(10)->toDateString()]);

        $this->fakeProfiles(
            [$this->finderItem(377, 'Mario Demo'), $this->finderItem(337, 'MARIO DEMINO')],
            [
                377 => $this->infoPage([['id' => 159, 'name' => 'Amir', 'role' => 'Portiere', 'tournaments' => [['id' => 144, 'name' => 'Uispic Premier', 'season' => '2025/2026', 'format' => 7]]]]),
                337 => $this->infoPage([['id' => 999, 'name' => 'Altro']]),
            ],
        );

        $result = app(PlayerProfileSyncer::class)->syncProfiles($this->own);

        $this->assertSame(1, $result['matched']);
        $this->assertSame(1, $result['photos']);
        $p->refresh();
        $this->assertSame(377, $p->xfive_person_id);
        $this->assertSame('Italia', $p->nationality);
        $this->assertSame('portiere', $p->role, 'il ruolo vuoto si completa da XFive');
        $this->assertSame(144, $p->xfive_profile['tournaments'][0]['id']);
        $this->assertSame("media/players/{$p->id}.png", $p->photo_path);
        $this->assertNotNull($p->xfive_synced_at);
    }

    public function test_an_existing_role_is_not_overwritten_and_matched_players_are_skipped_next_time(): void
    {
        $p = $this->player(extra: ['role' => 'attaccante']);
        $this->fakeProfiles([$this->finderItem(377, 'Mario Demo')], [377 => $this->infoPage([['id' => 159, 'name' => 'Amir', 'role' => 'Portiere']])]);

        app(PlayerProfileSyncer::class)->syncProfiles($this->own);
        $this->assertSame('attaccante', $p->refresh()->role);

        $again = app(PlayerProfileSyncer::class)->syncProfiles($this->own);
        $this->assertSame(1, $again['skipped']);
        $this->assertSame(0, $again['matched']);
    }

    public function test_namesakes_are_resolved_by_age_or_reported_as_ambiguous(): void
    {
        $this->player(extra: ['birth_date' => now()->subYears(29)->subDays(5)->toDateString()]);
        $both = [
            377 => $this->infoPage([['id' => 159, 'name' => 'Amir']], age: 24),
            378 => $this->infoPage([['id' => 159, 'name' => 'Amir']], age: 29),
        ];
        $finder = [$this->finderItem(377, 'Mario Demo'), $this->finderItem(378, 'Mario Demo')];

        $this->fakeProfiles($finder, $both);
        $this->assertSame(1, app(PlayerProfileSyncer::class)->syncProfiles($this->own)['matched']);
        $this->assertSame(378, Player::first()->xfive_person_id, "vince l'omonimo con l'età giusta");

        // stessa età: non si indovina (si riparte da una facciata Http pulita: il primo fake avrebbe la precedenza)
        Player::query()->update(['xfive_person_id' => null]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        $this->fakeProfiles($finder, [377 => $this->infoPage([['id' => 159, 'name' => 'Amir']], age: 29), 378 => $both[378]]);
        $result = app(PlayerProfileSyncer::class)->syncProfiles($this->own);

        $this->assertSame(['Mario Demo'], $result['ambiguous']);
        $this->assertNull(Player::first()->xfive_person_id);
    }

    public function test_players_without_a_profile_for_our_club_are_reported_not_guessed(): void
    {
        $this->player('Luca', 'Fantasma');
        $this->fakeProfiles([$this->finderItem(500, 'Luca Fantasma')], [500 => $this->infoPage([['id' => 777, 'name' => 'Altro Club']])]);

        $result = app(PlayerProfileSyncer::class)->syncProfiles($this->own);

        $this->assertSame(['Luca Fantasma'], $result['not_found']);
        $this->assertNull(Player::first()->xfive_person_id);
    }

    public function test_a_new_player_without_history_is_matched_only_with_exact_name_and_age(): void
    {
        $p = $this->player('Luca', 'Nuovo', ['birth_date' => now()->subYears(33)->subDays(20)->toDateString()]);

        // il profilo esiste ma elenca solo un altro club: serve anche l'età uguale
        $this->fakeProfiles([$this->finderItem(600, 'Luca Nuovo')], [600 => $this->infoPage([['id' => 202, 'name' => 'Altro Club', 'role' => 'Difensore']], age: 33)]);
        $result = app(PlayerProfileSyncer::class)->syncProfiles($this->own);

        $this->assertSame(1, $result['matched']);
        $p->refresh();
        $this->assertSame(600, $p->xfive_person_id);
        $this->assertSame('Italia', $p->nationality);
        $this->assertNull($p->role, 'il ruolo di un altro club non si adotta');
        $this->assertSame([], $p->xfive_profile['tournaments']);

        // età diversa: non è lui
        $p->update(['xfive_person_id' => null, 'nationality' => null]);
        Http::swap(new \Illuminate\Http\Client\Factory);
        $this->fakeProfiles([$this->finderItem(600, 'Luca Nuovo')], [600 => $this->infoPage([['id' => 202, 'name' => 'Altro Club']], age: 41)]);
        $result = app(PlayerProfileSyncer::class)->syncProfiles($this->own);

        $this->assertSame(['Luca Nuovo'], $result['not_found']);
        $this->assertNull($p->refresh()->xfive_person_id);
    }

    // ---------- statistiche ----------

    public function test_stats_are_read_from_the_tournament_tables_for_our_team_only(): void
    {
        $competition = Competition::create([
            'xfive_tournament_id' => 144, 'name' => 'Uispic Premier', 'season' => '2025/2026',
            'kind' => 'campionato', 'format' => 7, 'has_own_team' => true,
        ]);
        $mario = $this->player(extra: ['xfive_profile' => ['tournaments' => [['id' => 144]]]]);
        $this->player('Luca', 'Panchina', ['xfive_profile' => ['tournaments' => [['id' => 144]]]]);

        $this->fakeProfiles([], [], [
            'score' => $this->statsTable([
                ['name' => 'Demo M.', 'team' => 'AMIR COSTRUZIONI', 'values' => [7]],
                ['name' => 'Demo M.', 'team' => 'ALTRA SQUADRA', 'values' => [40]],
                ['name' => 'Exgiocatore X.', 'team' => 'AMIR COSTRUZIONI', 'values' => [2]],
            ], ['Goal']),
            'top-player' => $this->statsTable([['name' => 'Demo M.', 'team' => 'AMIR COSTRUZIONI', 'values' => [3]]], ['Punti']),
            'discipline' => $this->statsTable([['name' => 'Demo M.', 'team' => 'AMIR COSTRUZIONI', 'values' => [2, 1]]], ['Ammonizioni', 'Espulsioni']),
        ]);

        $result = app(PlayerProfileSyncer::class)->syncStats($this->own);

        $stat = PlayerCompetitionStat::where('player_id', $mario->id)->where('competition_id', $competition->id)->firstOrFail();
        $this->assertSame([7, 3, 2, 1], [$stat->goals, $stat->mvp_points, $stat->yellow, $stat->red], 'la riga di un altro club non conta');
        $this->assertSame(1, $result['unmatched_rows'], 'gli ex giocatori non si abbinano');
        $this->assertSame(2, PlayerCompetitionStat::count(), 'una riga (anche a zero) per chi ha partecipato');
        $this->assertSame(0, PlayerCompetitionStat::where('player_id', '!=', $mario->id)->value('goals'));
    }

    public function test_running_the_stats_again_rewrites_values_without_duplicates(): void
    {
        $competition = Competition::create([
            'xfive_tournament_id' => 144, 'name' => 'Uispic Premier', 'season' => '2025/2026',
            'kind' => 'campionato', 'format' => 7, 'has_own_team' => true,
        ]);
        $mario = $this->player(extra: ['xfive_profile' => ['tournaments' => [['id' => 144]]]]);
        $sync = fn (int $goals) => $this->fakeProfiles([], [], [
            'score' => $this->statsTable([['name' => 'Demo M.', 'team' => 'AMIR COSTRUZIONI', 'values' => [$goals]]], ['Goal']),
        ]);

        $sync(7);
        app(PlayerProfileSyncer::class)->syncStats($this->own);

        Http::swap(new \Illuminate\Http\Client\Factory);
        $sync(5); // XFive corregge i gol
        app(PlayerProfileSyncer::class)->syncStats($this->own);

        $this->assertSame(1, PlayerCompetitionStat::count());
        $this->assertSame(5, PlayerCompetitionStat::first()->goals);
    }

    // ---------- API ----------

    public function test_the_public_profile_and_the_career_leaderboard(): void
    {
        $mario = $this->player(extra: ['nationality' => 'Italia']);
        $luca = $this->player('Luca', 'Rossi');
        $old = Competition::create(['xfive_tournament_id' => 1, 'name' => 'Vecchio', 'season' => '2023/2024', 'kind' => 'campionato', 'format' => 7, 'has_own_team' => true]);
        $new = Competition::create(['xfive_tournament_id' => 2, 'name' => 'Nuovo', 'season' => '2025/2026', 'kind' => 'campionato', 'format' => 8, 'has_own_team' => true]);
        PlayerCompetitionStat::create(['player_id' => $mario->id, 'competition_id' => $old->id, 'goals' => 4, 'yellow' => 1]);
        PlayerCompetitionStat::create(['player_id' => $mario->id, 'competition_id' => $new->id, 'goals' => 6, 'mvp_points' => 2, 'red' => 1]);
        PlayerCompetitionStat::create(['player_id' => $luca->id, 'competition_id' => $new->id, 'goals' => 1]);

        $profile = $this->getJson("/api/v1/public/players/{$mario->id}")->assertOk()->json('data');

        $this->assertSame('Mario Demo', $profile['player']['full_name']);
        $this->assertSame('Italia', $profile['player']['nationality']);
        // senza distinte lette valgono le classifiche ufficiali di XFive
        $totals = $profile['totals'];
        $this->assertSame([2, 10, 1, 1, 2, 0], [$totals['competitions'], $totals['goals'], $totals['yellow'], $totals['red'], $totals['mvp_points'], $totals['matches']]);
        $this->assertSame(['2025/2026', '2023/2024'], array_column(array_column($profile['career'], 'competition'), 'season'), 'dalla stagione più recente');
        foreach (['phone', 'email', 'birth_date', 'access_token', 'magic_link'] as $private) {
            $this->assertArrayNotHasKey($private, $profile['player']);
        }

        $career = $this->getJson('/api/v1/public/stats/career')->assertOk()->json('data');
        $this->assertSame(['Mario Demo', 'Luca Rossi'], array_column(array_column($career, 'player'), 'full_name'));
        $this->assertSame(10, $career[0]['goals']);

        // chi non è più in rosa resta nello storico: la sua scheda è ancora visibile
        $mario->update(['is_active' => false]);
        $this->getJson("/api/v1/public/players/{$mario->id}")->assertOk()->assertJsonPath('data.player.is_active', false);
    }

    public function test_the_player_photo_is_served_and_replaces_the_remote_url(): void
    {
        $p = $this->player(extra: ['photo_url' => 'https://example.com/remote.png']);
        $this->assertSame('https://example.com/remote.png', \App\Support\Present::publicPlayer($p)['photo_url']);

        app(\App\Services\MediaStore::class)->put("media/players/{$p->id}.png", $this->png(), 'image/png');
        $p->update(['photo_path' => "media/players/{$p->id}.png"]);

        $this->get("/api/v1/public/players/{$p->id}/photo")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringEndsWith("/api/v1/public/players/{$p->id}/photo", \App\Support\Present::publicPlayer($p->refresh())['photo_url']);

        // gli ex giocatori mantengono la foto: compaiono nello storico
        $p->update(['is_active' => false]);
        $this->get("/api/v1/public/players/{$p->id}/photo")->assertOk();
    }

    public function test_images_saved_on_disk_by_older_versions_are_still_served_and_can_be_moved_into_the_database(): void
    {
        Storage::disk('local')->put("media/players/9.jpg", $this->png());
        $p = $this->player();
        $p->update(['photo_path' => 'media/players/9.jpg']);

        $this->get("/api/v1/public/players/{$p->id}/photo")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('media_files')->count());

        $this->artisan('amir:media-import')->expectsOutputToContain('1')->assertSuccessful();

        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('media_files')->count());
        Storage::disk('local')->delete('media/players/9.jpg');
        $this->get("/api/v1/public/players/{$p->id}/photo")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }
}
