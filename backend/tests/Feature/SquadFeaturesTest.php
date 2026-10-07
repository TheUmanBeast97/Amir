<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Game;
use App\Models\MatchPlayerStat;
use App\Models\Player;
use App\Models\PlayerCompetitionStat;
use App\Models\Team;
use App\Models\TeamEvent;
use App\Models\User;
use App\Services\Xfive\CompetitionSyncer;
use App\Services\Xfive\MatchDetailsSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Import dei dettagli partita, competizioni escluse, amichevoli, convocati e
 * pubblicazione, scheda giocatore. Pagine di esempio con nomi inventati.
 */
class SquadFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private Team $own;

    private Team $rival;

    protected function setUp(): void
    {
        parent::setUp();
        config(['amir.xfive.throttle_ms' => 0]);
        Storage::fake('local');
        $this->own = Team::create(['name' => 'AMIR COSTRUZIONI', 'short_name' => 'AMIR', 'xfive_club_id' => 159, 'is_own' => true, 'format' => 8]);
        $this->rival = Team::create(['name' => 'PROJECT S', 'xfive_club_id' => 200]);
    }

    // ---------- helper ----------

    private function admin(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function player(string $first, string $last, array $extra = []): Player
    {
        return Player::create(['team_id' => $this->own->id, 'first_name' => $first, 'last_name' => $last] + $extra);
    }

    private function competition(array $extra = []): Competition
    {
        static $n = 1000;

        return Competition::create($extra + [
            'xfive_tournament_id' => ++$n, 'name' => 'Torneo '.$n, 'season' => '2025/2026',
            'kind' => 'campionato', 'format' => 8, 'is_current' => false, 'has_own_team' => true,
        ]);
    }

    private function game(Competition $c, string $date, int $us, int $them, bool $ownHome = true, ?int $xfiveId = null): Game
    {
        return Game::create([
            'xfive_match_id' => $xfiveId,
            'competition_id' => $c->id, 'round' => 1, 'round_label' => '1ª giornata',
            'home_team_id' => $ownHome ? $this->own->id : $this->rival->id,
            'away_team_id' => $ownHome ? $this->rival->id : $this->own->id,
            'kickoff_at' => $date.' 21:00:00',
            'home_score' => $ownHome ? $us : $them, 'away_score' => $ownHome ? $them : $us,
            'status' => Game::PLAYED,
        ]);
    }

    private function upcoming(): Game
    {
        $c = $this->competition(['is_current' => true, 'season' => '2026/2027']);

        return Game::create([
            'competition_id' => $c->id, 'round' => 1, 'round_label' => '1ª giornata',
            'home_team_id' => $this->own->id, 'away_team_id' => $this->rival->id,
            'kickoff_at' => now()->addDays(4), 'status' => Game::SCHEDULED,
        ]);
    }

    private function matchPage(string $name): string
    {
        return $this->fixture($name.'.html');
    }

    private function fakeMatchPages(string $matchHtml): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/it/match/*' => Http::response($matchHtml, 200),
            'cdn.enjore.com/*' => Http::response("\x89PNG\r\n\x1a\n".str_repeat('x', 800), 200, ['Content-Type' => 'image/png']),
        ]);
    }

    private function stat(Game $g, Player $p, array $values = []): MatchPlayerStat
    {
        return MatchPlayerStat::create(['match_id' => $g->id, 'player_id' => $p->id, 'played' => true] + $values);
    }

    // ---------- import dei dettagli partita ----------

    public function test_the_match_import_fills_stats_creates_former_players_and_can_be_repeated(): void
    {
        $andrea = $this->player('Andrea', 'Blu');
        $simone = $this->player('Simone', 'Grigi');
        $game = $this->game($this->competition(), '2025-10-20', 8, 7, ownHome: false, xfiveId: 5001);
        $this->fakeMatchPages($this->matchPage('match-with-referee-and-mvp'));

        $syncer = app(MatchDetailsSyncer::class);
        $r = $syncer->syncGame($game, $this->own);

        $this->assertSame(['ok', 9, 7, false], [$r['status'], $r['stats'], $r['created'], $r['mismatch']]);

        $game->refresh();
        $this->assertSame('Arbitro Esempio', $game->referee);
        $this->assertNotNull($game->details_synced_at);
        $this->assertSame($simone->id, $game->man_of_the_match_id);
        $this->assertSame('away', $game->details['own_side']);
        $this->assertCount(8, $game->details['home']['lineup'], 'la distinta avversaria si conserva');

        $this->assertSame(9, MatchPlayerStat::where('match_id', $game->id)->count());
        $blu = MatchPlayerStat::where('match_id', $game->id)->where('player_id', $andrea->id)->first();
        $this->assertSame([2, 'xfive', true], [$blu->goals, $blu->source, $blu->played]);
        $this->assertTrue((bool) MatchPlayerStat::where('player_id', $simone->id)->first()->is_mvp);

        $former = Player::where('last_name', 'Rosa')->firstOrFail();
        $this->assertSame('Davide', $former->first_name);
        $this->assertFalse($former->is_active);
        $this->assertStringContainsString('Ex giocatore', $former->notes);
        $this->assertSame(7, Player::where('is_active', false)->count());
        $this->assertTrue(Player::whereNotNull('photo_path')->exists(), 'foto degli ex giocatori scaricate');

        // rilanciare non duplica niente
        $again = $syncer->syncGame($game, $this->own);
        $this->assertSame(0, $again['created']);
        $this->assertSame(9, Player::count());
        $this->assertSame(9, MatchPlayerStat::where('match_id', $game->id)->count());
    }

    public function test_a_shorter_or_longer_name_matches_the_squad_player_instead_of_creating_a_duplicate(): void
    {
        $p = $this->player('Stefano Maria', 'Marrone'); // in distinta compare come "Marrone Stefano"
        $game = $this->game($this->competition(), '2025-10-20', 8, 7, ownHome: false, xfiveId: 5001);
        $this->fakeMatchPages($this->matchPage('match-with-referee-and-mvp'));

        app(MatchDetailsSyncer::class)->syncGame($game, $this->own);

        $this->assertSame(1, Player::where('last_name', 'Marrone')->count());
        $this->assertSame(1, MatchPlayerStat::where('player_id', $p->id)->first()->goals);
    }

    public function test_manual_statistics_are_never_overwritten_by_the_import(): void
    {
        $andrea = $this->player('Andrea', 'Blu');
        $game = $this->game($this->competition(), '2025-10-20', 8, 7, ownHome: false, xfiveId: 5001);
        $this->stat($game, $andrea, ['goals' => 9, 'source' => 'manual']);
        $this->fakeMatchPages($this->matchPage('match-with-referee-and-mvp'));

        $r = app(MatchDetailsSyncer::class)->syncGame($game, $this->own);

        $this->assertSame(0, $r['stats']);
        $this->assertSame(1, MatchPlayerStat::where('match_id', $game->id)->count());
        $this->assertSame(9, MatchPlayerStat::first()->goals);
        $this->assertSame('Arbitro Esempio', $game->refresh()->referee, 'arbitro e dettagli si aggiornano comunque');
    }

    public function test_xfive_placeholder_players_are_not_people(): void
    {
        $game = $this->game($this->competition(), '2025-10-20', 8, 7, ownHome: false, xfiveId: 5001);
        $this->fakeMatchPages(str_replace('>Blu Andrea<', '>00 00<', $this->matchPage('match-with-referee-and-mvp')));

        $r = app(MatchDetailsSyncer::class)->syncGame($game, $this->own);

        $this->assertSame(8, $r['stats']);
        $this->assertSame(0, Player::where('last_name', '00')->count());
    }

    public function test_the_import_command_skips_excluded_competitions_and_friendlies(): void
    {
        $normal = $this->game($this->competition(), '2025-10-20', 8, 7, ownHome: false, xfiveId: 1);
        $excluded = $this->game($this->competition(['is_excluded' => true]), '2025-10-21', 8, 7, ownHome: false, xfiveId: 2);
        $friendly = $this->game($this->competition(['kind' => Competition::KIND_FRIENDLY, 'xfive_tournament_id' => null]), '2025-10-22', 1, 0, xfiveId: 3);
        $this->fakeMatchPages($this->matchPage('match-with-referee-and-mvp'));

        $this->artisan('xfive:matches')->assertSuccessful();

        $this->assertNotNull($normal->refresh()->details_synced_at);
        $this->assertNull($excluded->refresh()->details_synced_at);
        $this->assertNull($friendly->refresh()->details_synced_at);

        $this->artisan('xfive:matches')->expectsOutputToContain('Niente da leggere')->assertSuccessful();
    }

    // ---------- competizioni escluse ----------

    public function test_the_sync_flags_configured_tournaments_as_excluded(): void
    {
        config(['amir.excluded_tournaments' => [139]]);
        $syncer = app(CompetitionSyncer::class);

        $syncer->sync(['id' => 139, 'name' => 'Serie A [Alessandria]', 'format' => 8, 'season' => '2025/2026', 'own' => true], [], false);
        $syncer->sync(['id' => 144, 'name' => 'Uispic Premier', 'format' => 7, 'season' => '2025/2026', 'own' => true], [], false);

        $this->assertTrue(Competition::where('xfive_tournament_id', 139)->value('is_excluded'));
        $this->assertFalse(Competition::where('xfive_tournament_id', 144)->value('is_excluded'));
    }

    public function test_excluded_competitions_do_not_count_in_history_head_to_head_or_player_stats(): void
    {
        $counted = $this->competition(['name' => 'Uispic Premier']);
        $excluded = $this->competition(['name' => 'Serie A', 'is_excluded' => true]);
        $p = $this->player('Mario', 'Demo');

        $g1 = $this->game($counted, '2026-01-10', 3, 1);
        $g2 = $this->game($excluded, '2026-01-17', 5, 0);
        $this->stat($g1, $p, ['goals' => 1]);
        $this->stat($g2, $p, ['goals' => 4]);

        $history = $this->getJson('/api/v1/public/history')->assertOk()->json('data');
        $this->assertSame(1, $history['summary']['record']['played']);
        $this->assertSame(['Uispic Premier'], array_column($history['seasons'][0]['competitions'], 'name'));

        $this->getJson("/api/v1/public/history/competitions/{$excluded->id}")->assertNotFound();
        $this->getJson("/api/v1/public/history/competitions/{$counted->id}")->assertOk();

        $this->getJson("/api/v1/public/head-to-head/{$this->rival->id}")->assertOk()->assertJsonPath('data.played', 1);

        $totals = $this->getJson("/api/v1/public/players/{$p->id}")->assertOk()->json('data.totals');
        $this->assertSame([1, 1], [$totals['matches'], $totals['goals']]);
    }

    // ---------- amichevoli ----------

    public function test_friendlies_can_be_created_scored_and_deleted(): void
    {
        $this->admin();

        $created = $this->postJson('/api/v1/friendlies', [
            'opponent_name' => 'Real Madrink', 'kickoff_at' => '2026-11-05 21:00', 'venue' => 'Campo Sportivo',
            'is_home' => false, 'our_kit' => 'white',
        ])->assertCreated()->json('data');

        $match = $created['match'];
        $this->assertTrue($match['is_friendly']);
        $this->assertSame('scheduled', $match['status']);
        $this->assertSame('REAL MADRINK', $match['home_team']['name']);
        $this->assertTrue($match['away_team']['is_own']);
        $this->assertSame('white', $match['our_kit']);
        $this->assertNotNull($created['event']['id'], 'serve per RSVP e promemoria');

        $competition = Competition::where('kind', 'amichevole')->firstOrFail();
        $this->assertSame(['2026/2027', true, null], [$competition->season, $competition->is_current, $competition->xfive_tournament_id]);
        $this->assertSame(0, Competition::counted()->where('kind', 'amichevole')->count(), 'non conta per le statistiche');

        $this->getJson('/api/v1/friendlies')->assertOk()->assertJsonCount(1, 'data');

        $this->putJson("/api/v1/friendlies/{$match['id']}", ['home_score' => 2])->assertUnprocessable();

        $played = $this->putJson("/api/v1/friendlies/{$match['id']}", ['home_score' => 2, 'away_score' => 3])
            ->assertOk()->json('data.match');
        $this->assertSame(['played', 'W'], [$played['status'], $played['result']], 'abbiamo vinto in trasferta 2-3');

        // lo stesso avversario si riusa
        $this->postJson('/api/v1/friendlies', ['opponent_name' => 'real madrink', 'kickoff_at' => '2026-12-01 21:00'])->assertCreated();
        $this->assertSame(1, Team::where('name', 'REAL MADRINK')->count());

        $this->deleteJson("/api/v1/friendlies/{$match['id']}")->assertOk();
        $this->assertNull(Game::find($match['id']));
        $this->assertSame(1, TeamEvent::count());
    }

    public function test_only_friendlies_can_be_edited_through_the_friendlies_endpoint(): void
    {
        $this->admin();
        $official = $this->upcoming();

        $this->putJson("/api/v1/friendlies/{$official->id}", ['venue' => 'altrove'])->assertNotFound();
        $this->deleteJson("/api/v1/friendlies/{$official->id}")->assertNotFound();
        $this->assertNotNull($official->fresh());
    }

    public function test_a_friendly_before_the_season_starts_is_the_next_match_on_the_home_page(): void
    {
        $this->admin();
        $official = $this->upcoming();
        $official->update(['kickoff_at' => now()->addDays(30)]);

        $friendly = $this->postJson('/api/v1/friendlies', ['opponent_name' => 'Amici', 'kickoff_at' => now()->addDays(3)->toDateTimeString()])
            ->assertCreated()->json('data.match');

        $next = $this->getJson('/api/v1/public/home')->assertOk()->json('data.next_match');
        $this->assertSame($friendly['id'], $next['id']);
        $this->assertTrue($next['is_friendly']);
    }

    // ---------- convocati, formazione e pubblicazione ----------

    public function test_callups_and_lineup_show_on_the_public_match_only_after_publishing(): void
    {
        $this->admin();
        $a = $this->player('Anna', 'Uno', ['shirt_number_red' => '7', 'shirt_number_white' => '17', 'phone' => '333']);
        $b = $this->player('Bruno', 'Due');
        $game = $this->upcoming();

        $this->putJson("/api/v1/matches/{$game->id}/callups", ['players' => [
            ['player_id' => $a->id, 'note' => 'Capitano'], ['player_id' => $b->id],
        ]])->assertOk()->assertJsonCount(2, 'data.callups')->assertJsonPath('data.callups_published', false);

        $public = $this->getJson("/api/v1/public/matches/{$game->id}")->assertOk()->json('data');
        $this->assertNull($public['callups']);
        $this->assertNull($public['lineup']);
        $this->assertNull($public['report']);

        $this->putJson("/api/v1/matches/{$game->id}/callups", ['published' => true, 'players' => [
            ['player_id' => $a->id, 'note' => 'Capitano'], ['player_id' => $b->id],
        ]])->assertOk()->assertJsonPath('data.callups_published', true);

        $callups = $this->getJson("/api/v1/public/matches/{$game->id}")->json('data.callups');
        $this->assertSame(['Bruno Due', 'Anna Uno'], array_column(array_column($callups, 'player'), 'full_name'), 'in ordine di cognome');
        $this->assertSame(['7', '17', 'Capitano'], [$callups[1]['player']['shirt_number_red'], $callups[1]['player']['shirt_number_white'], $callups[1]['note']]);
        $this->assertArrayNotHasKey('phone', $callups[1]['player']);

        // l'elenco inviato è quello completo
        $this->putJson("/api/v1/matches/{$game->id}/callups", ['players' => [['player_id' => $b->id]]])->assertOk();
        $this->assertCount(1, $this->getJson("/api/v1/public/matches/{$game->id}")->json('data.callups'));

        $this->patchJson("/api/v1/matches/{$game->id}", ['callups_published' => false])->assertOk();
        $this->assertNull($this->getJson("/api/v1/public/matches/{$game->id}")->json('data.callups'));
    }

    public function test_a_lineup_must_exist_before_it_is_published_and_the_kit_is_validated(): void
    {
        $this->admin();
        $a = $this->player('Anna', 'Uno');
        $game = $this->upcoming();

        $this->patchJson("/api/v1/matches/{$game->id}", ['lineup_published' => true])->assertUnprocessable();
        $this->patchJson("/api/v1/matches/{$game->id}", ['our_kit' => 'blu'])->assertUnprocessable();

        $slots = [];
        foreach (range(1, 8) as $i) {
            $slots[] = ['slot' => $i, 'player_id' => $i === 1 ? $a->id : null, 'label' => $i === 1 ? 'POR' : 'P'.$i, 'x' => 10 * $i, 'y' => 50];
        }
        $this->putJson("/api/v1/matches/{$game->id}/lineup", ['formation' => '3-3-1', 'slots' => $slots, 'is_published' => true])
            ->assertOk()->assertJsonPath('data.is_published', true);
        $this->patchJson("/api/v1/matches/{$game->id}", ['our_kit' => 'white'])->assertOk()->assertJsonPath('data.our_kit', 'white');

        $public = $this->getJson("/api/v1/public/matches/{$game->id}")->assertOk()->json('data');
        $this->assertSame('3-3-1', $public['lineup']['formation']);
        $this->assertSame('Anna Uno', $public['lineup']['players'][$a->id]['full_name']);
        $this->assertSame('white', $public['match']['our_kit']);

        $this->patchJson("/api/v1/matches/{$game->id}", ['lineup_published' => false])->assertOk();
        $this->assertNull($this->getJson("/api/v1/public/matches/{$game->id}")->json('data.lineup'));
    }

    public function test_the_public_match_shows_scorers_cards_referee_and_best_player_of_a_played_match(): void
    {
        $andrea = $this->player('Andrea', 'Blu', ['phone' => '333']);
        $luca = $this->player('Luca', 'Verdi');
        $game = $this->game($this->competition(), '2026-01-10', 3, 1);
        $this->stat($game, $andrea, ['goals' => 2, 'is_mvp' => true]);
        $this->stat($game, $luca, ['yellow' => 1]);
        $game->update([
            'referee' => 'Mario Arbitro', 'man_of_the_match_id' => $andrea->id, 'venue' => 'Campo 4',
            'details' => ['own_side' => 'home', 'away' => [
                'lineup' => [['name' => 'Rossi Gino', 'goals' => 1, 'yellow' => 0, 'red' => 1, 'mvp' => false], ['name' => 'Neri Ugo', 'goals' => 0, 'yellow' => 0, 'red' => 0, 'mvp' => false]],
                'scorers' => [['name' => 'Rossi Gino', 'goals' => 1]],
            ]],
        ]);

        $report = $this->getJson("/api/v1/public/matches/{$game->id}")->assertOk()->json('data.report');

        $this->assertSame('Mario Arbitro', $report['referee']);
        $this->assertSame('Andrea Blu', $report['man_of_the_match']['full_name']);
        $this->assertSame(['Andrea Blu'], array_column(array_column($report['scorers'], 'player'), 'full_name'));
        $this->assertSame(2, $report['scorers'][0]['goals']);
        $this->assertSame([['name' => 'Rossi Gino', 'yellow' => 0, 'red' => 1]], $report['opponent']['cards']);
        $this->assertSame(['Rossi Gino'], array_column($report['opponent']['scorers'], 'name'));
        $this->assertArrayNotHasKey('phone', $report['players'][0]['player']);
    }

    public function test_matches_of_other_teams_and_cancelled_ones_are_not_public(): void
    {
        $other = Team::create(['name' => 'ALTRI', 'xfive_club_id' => 300]);
        $c = $this->competition();
        $foreign = Game::create(['competition_id' => $c->id, 'home_team_id' => $this->rival->id, 'away_team_id' => $other->id, 'status' => Game::SCHEDULED]);
        $cancelled = $this->upcoming();
        $cancelled->update(['status' => Game::CANCELLED]);

        $this->getJson("/api/v1/public/matches/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/v1/public/matches/{$cancelled->id}")->assertNotFound();
    }

    // ---------- pagamenti e numeri di maglia ----------

    public function test_revolut_is_an_accepted_payment_method(): void
    {
        $this->admin();
        $this->player('Anna', 'Uno');
        $this->postJson('/api/v1/charges', ['title' => 'Quota', 'kind' => 'quota_stagione', 'amount_cents' => 10000])->assertCreated();
        $pc = \App\Models\PlayerCharge::firstOrFail();

        $this->postJson("/api/v1/player-charges/{$pc->id}/payments", ['amount_cents' => 4000, 'method' => 'revolut', 'paid_at' => now()->toDateString()])
            ->assertCreated()->assertJsonPath('data.balance_cents', 6000);
        $this->postJson("/api/v1/player-charges/{$pc->id}/payments", ['amount_cents' => 100, 'method' => 'bitcoin', 'paid_at' => now()->toDateString()])
            ->assertUnprocessable();
    }

    public function test_players_have_a_red_and_a_white_shirt_number(): void
    {
        $this->admin();

        $id = $this->postJson('/api/v1/players', [
            'first_name' => 'Luca', 'last_name' => 'Verdi', 'shirt_number_red' => '9', 'shirt_number_white' => '19',
        ])->assertCreated()->assertJsonPath('data.shirt_number_red', '9')->assertJsonPath('data.shirt_number_white', '19')->json('data.id');

        $this->putJson("/api/v1/players/{$id}", ['shirt_number_white' => '29'])->assertOk()->assertJsonPath('data.shirt_number_white', '29');

        $roster = $this->getJson('/api/v1/public/roster')->assertOk()->json('data');
        $this->assertSame(['9', '29'], [$roster[0]['shirt_number_red'], $roster[0]['shirt_number_white']]);

        $this->postJson('/api/v1/players/import', ['rows' => [['Nome' => 'Mario', 'Cognome' => 'Rossi', 'Numero rosso' => '4', 'Numero bianco' => '14']]])
            ->assertOk()->assertJsonPath('data.created', 1);
        $imported = Player::where('last_name', 'Rossi')->firstOrFail();
        $this->assertSame(['4', '14'], [$imported->shirt_number_red, $imported->shirt_number_white]);
    }

    // ---------- scheda personale del giocatore ----------

    public function test_the_player_page_has_totals_seasons_form_impact_and_records(): void
    {
        $league = $this->competition(['name' => 'Campionato']);
        $cup = $this->competition(['name' => 'Coppa', 'kind' => 'coppa']);
        $p = $this->player('Mario', 'Demo', ['is_active' => true]);
        $ex = $this->player('Gino', 'Ex', ['is_active' => false]);

        $g1 = $this->game($league, '2026-01-10', 3, 1);                 // V
        $g2 = $this->game($league, '2026-01-17', 0, 2, ownHome: false); // P
        $g3 = $this->game($cup, '2026-01-24', 4, 0);                    // V
        $g4 = $this->game($league, '2026-01-31', 1, 1);                 // N (senza Mario)
        $g5 = $this->game($league, '2026-02-07', 5, 0);                 // V (senza Mario)

        $this->stat($g1, $p, ['goals' => 2, 'is_mvp' => true]);
        $this->stat($g2, $p, ['yellow' => 1]);
        $this->stat($g3, $p, ['goals' => 1]);
        foreach ([$g1, $g4, $g5] as $g) {
            $this->stat($g, $ex, ['goals' => 1]);
        }

        $page = $this->getJson("/api/v1/public/players/{$p->id}")->assertOk()->json('data');
        $t = $page['totals'];

        $this->assertSame([3, 3, 1, 1, 2, 0, 1], [$t['matches'], $t['goals'], $t['yellow'], $t['mvp'], $t['wins'], $t['draws'], $t['losses']]);
        $this->assertEquals(1.0, $t['goals_per_match']);
        $this->assertSame(['2025/2026', 3, 3], [$page['by_season'][0]['season'], $page['by_season'][0]['matches'], $page['by_season'][0]['goals']]);
        $this->assertSame(['campionato' => 2, 'coppa' => 1], array_column($page['by_kind'], 'matches', 'kind'));
        $this->assertSame(['V', 'P', 'V'], array_map(fn ($r) => ['W' => 'V', 'D' => 'N', 'L' => 'P'][$r['result']], $page['form']));
        $this->assertSame('2026-01-24', $page['match_log'][0]['date'], 'il registro parte dalla più recente');

        // con lui 2 vittorie e 1 sconfitta (6 punti su 3); senza di lui un pareggio e una vittoria (4 su 2)
        $this->assertSame([3, 2], [$page['impact']['with']['played'], $page['impact']['without']['played']]);
        $this->assertEquals([2.0, 2.0], [$page['impact']['with']['points_per_match'], $page['impact']['without']['points_per_match']]);
        $this->assertSame(5, $page['impact']['sample']);

        $this->assertSame('2026-01-10', $page['records']['debut']['date']);
        $this->assertSame(2, $page['records']['most_goals_in_match']['goals']);
        $this->assertSame(1, $page['records']['scoring_streak']);
        $this->assertSame(['appearances' => 1, 'goals' => 1, 'of' => 2], $page['rank']);
    }

    public function test_the_all_time_leaderboard_includes_former_players_and_uses_the_best_figure_per_tournament(): void
    {
        $league = $this->competition();
        $p = $this->player('Mario', 'Demo');
        $ex = $this->player('Gino', 'Ex', ['is_active' => false]);
        $g = $this->game($league, '2026-01-10', 3, 1);
        $this->stat($g, $p, ['goals' => 1]);
        $this->stat($g, $ex, ['goals' => 2]);
        // XFive dichiara 4 gol nel torneo ma la distinta ne mostra 1: vale il più alto
        PlayerCompetitionStat::create(['player_id' => $p->id, 'competition_id' => $league->id, 'goals' => 4]);

        $rows = $this->getJson('/api/v1/public/stats/career')->assertOk()->json('data');

        $byName = collect($rows)->keyBy(fn ($r) => $r['player']['full_name']);
        $this->assertSame(4, $byName['Mario Demo']['goals']);
        $this->assertSame(1, $byName['Mario Demo']['matches']);
        $this->assertSame([2, false], [$byName['Gino Ex']['goals'], $byName['Gino Ex']['player']['is_active']]);

        $this->assertSame(4, $this->getJson("/api/v1/public/players/{$p->id}")->json('data.totals.goals'));
    }

    public function test_the_player_page_is_only_for_players_of_our_team(): void
    {
        $other = Team::create(['name' => 'ALTRI', 'xfive_club_id' => 300]);
        $foreign = Player::create(['team_id' => $other->id, 'first_name' => 'Altro', 'last_name' => 'Giocatore']);

        $this->getJson("/api/v1/public/players/{$foreign->id}")->assertNotFound();
    }
}
