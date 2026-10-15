<?php

namespace Tests\Feature;

use App\Models\Competition;
use App\Models\Game;
use App\Models\MatchCallup;
use App\Models\OfficialSheet;
use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use App\Models\XfiveSession;
use App\Services\Xfive\Admin\XfiveSessionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdminRosterFixtures;
use Tests\Support\SheetFixtures;
use Tests\TestCase;

/**
 * «Collega XFive» e distinta ufficiale: accesso con le credenziali digitate, sessione cifrata, modulo di XFive, invio, numeri di
 * maglia ricordati. XFive è simulato e i nomi sono inventati.
 */
class OfficialSheetTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password-segreta-di-prova-9';

    private Team $own;

    private Game $game;

    private User $user;

    /** @var array<int, string> le richieste fatte a XFive, come «POST /percorso» */
    private array $calls = [];

    /** XFive ha chiuso la sessione: le chiamate AJAX rispondono con la pagina del sito */
    private bool $expired = false;

    /** il codice con cui XFive risponde alle richieste di team_schedule.php (200 = normale) */
    private int $scheduleStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'amir.xfive.throttle_ms' => 0,
            'amir.xfive.base_url' => 'https://www.xfivesport.it',
            'amir.own.club_id' => 159,
            'amir.xfive_admin.login_max_failures' => 3,
            'amir.xfive_admin.login_block_minutes' => 15,
            'amir.xfive_admin.session_minutes' => 120,
        ]);

        $this->own = Team::create(['name' => 'AMIR COSTRUZIONI', 'short_name' => 'AMIR', 'xfive_club_id' => 159, 'is_own' => true, 'format' => 8]);
        $rival = Team::create(['name' => 'ALFA', 'xfive_club_id' => 200]);
        $c = Competition::create(['xfive_tournament_id' => 1, 'name' => 'Cittadella', 'season' => '2026/2027', 'kind' => 'campionato', 'format' => 8, 'is_current' => true, 'has_own_team' => true]);
        $this->game = Game::create([
            'xfive_match_id' => 14584, 'competition_id' => $c->id, 'round' => 1, 'round_label' => '1ª giornata',
            'home_team_id' => $this->own->id, 'away_team_id' => $rival->id, 'kickoff_at' => now()->addHours(2), 'status' => Game::SCHEDULED,
        ]);

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function player(string $first, string $last, array $extra = []): Player
    {
        return Player::create(['team_id' => $this->own->id, 'first_name' => $first, 'last_name' => $last] + $extra);
    }

    /**
     * XFive simulato. Il login (login.php) vale solo con la password giusta e mette un cookie; poi le pagine mostrano «Esci».
     *
     * @param  array<int, int>  $open  partite con la distinta apribile (id => squadra)
     * @param  array<int, array>  $players  il modulo (vedi SheetFixtures::form)
     */
    private function fakeXfiveSheet(array $open = [14584 => 3604], array $players = [], ?string $saveResponse = null): void
    {
        $loggedIn = false;
        $saveResponse ??= (string) json_encode(['success' => 'Distinta salvata correttamente']);

        Http::fake(function (Request $request) use (&$loggedIn, $open, $players, $saveResponse) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = $request->method().' '.$path;

            if ($path === '/login.php') {
                $loggedIn = ($request['password'] ?? null) === self::PASSWORD;

                return Http::response('', 302, ['Location' => 'https://www.xfivesport.it/', 'Set-Cookie' => 'PHPSESSID=segreto-di-sessione-xyz; path=/; domain=.xfivesport.it; secure; HttpOnly']);
            }
            if ($path === '/logout.php') {
                $loggedIn = false;

                return Http::response('', 200);
            }
            if (! $loggedIn || ($this->expired && str_ends_with($path, '/team_schedule.php'))) {
                return Http::response('<html><body><a onclick="suggestionFeedback(\'login\')">Accedi</a></body></html>');
            }
            if (str_ends_with($path, '/manage_tournament/team.php')) {
                return Http::response(AdminRosterFixtures::ajaxJson(), 200, ['Content-Type' => 'application/json']);
            }
            if (str_ends_with($path, '/manage_tournament/team_schedule.php')) {
                if ($this->scheduleStatus !== 200) {
                    return Http::response('', $this->scheduleStatus);
                }

                // come su XFive (provato): length=-1 dà 500; la data va nel formato della pagina (g/m/aaaa)
                if ((int) ($request['op'] ?? 0) === 1 && ((int) ($request['length'] ?? 0) <= 0 || ! preg_match('#^\d{2}/\d{2}/\d{4}$#', (string) ($request['date_from'] ?? '')))) {
                    return Http::response('', 500);
                }

                return Http::response(match ((int) ($request['op'] ?? 0)) {
                    1 => SheetFixtures::schedule($open),
                    2 => isset($open[(int) $request['mid']]) ? SheetFixtures::form($players) : (string) json_encode(['errors' => 'Distinta non disponibile']),
                    3 => $saveResponse,
                    default => '{}',
                }, 200, ['Content-Type' => 'application/json']);
            }

            return Http::response('<html><body>Amministrazione <a href="https://www.xfivesport.it/logout.php">Esci</a></body></html>');
        });
    }

    private function connect(string $password = self::PASSWORD)
    {
        return $this->postJson('/api/v1/xfive-session', ['email' => 'capo@example.com', 'password' => $password]);
    }

    // ---------- collegamento ----------

    public function test_connect_stores_an_encrypted_session_and_never_the_password(): void
    {
        $this->fakeXfiveSheet();

        $this->connect()->assertOk()->assertJsonPath('data.connected', true)->assertJsonPath('data.email_hint', 'c***@example.com');

        $row = XfiveSession::firstOrFail();
        $raw = json_encode($row->getAttributes());
        $this->assertStringNotContainsString(self::PASSWORD, $raw);
        $this->assertStringNotContainsString('segreto-di-sessione-xyz', $raw, 'il cookie si conserva solo cifrato');
        $this->assertStringNotContainsString('capo@example.com', $raw, "dell'email resta solo un'anteprima");
        $this->assertTrue($row->expires_at->isFuture());

        $cookies = app(XfiveSessionStore::class)->cookies($row);
        $this->assertSame('segreto-di-sessione-xyz', collect($cookies)->firstWhere('Name', 'PHPSESSID')['Value'] ?? null, 'decifrato, il cookie di XFive c\'è');

        $this->getJson('/api/v1/xfive-session')->assertOk()->assertJsonPath('data.connected', true);
    }

    public function test_wrong_password_is_refused_and_repeated_failures_block_for_a_while(): void
    {
        $this->fakeXfiveSheet();

        $this->connect('sbagliata')->assertStatus(422)->assertJsonPath('state', 'login_failed');
        $this->connect('sbagliata')->assertStatus(422);
        $this->connect('sbagliata')->assertStatus(422);

        // dopo tre rifiuti di fila non si riprova nemmeno con la password giusta, per non far bloccare l'account
        $before = count($this->calls);
        $this->connect()->assertStatus(429)->assertJsonPath('state', 'blocked');
        $this->assertSame($before, count($this->calls), 'durante il blocco non si contatta XFive');
        $this->assertSame(0, XfiveSession::count());
    }

    public function test_disconnect_logs_out_of_xfive_and_forgets_the_session(): void
    {
        $this->fakeXfiveSheet();
        $this->connect()->assertOk();

        $this->deleteJson('/api/v1/xfive-session')->assertOk()->assertJsonPath('data.connected', false);

        $this->assertSame(0, XfiveSession::count());
        $this->assertContains('GET /logout.php', $this->calls);
    }

    // ---------- distinta ----------

    public function test_without_xfive_the_sheet_still_shows_the_roster_with_suggested_numbers(): void
    {
        $mario = $this->player('Mario', 'Rossi', ['shirt_number' => '7']);
        $this->player('Luca', 'Bianchi');
        MatchCallup::create(['match_id' => $this->game->id, 'player_id' => $mario->id]);

        $r = $this->getJson("/api/v1/matches/{$this->game->id}/sheet")->assertOk();

        $r->assertJsonPath('data.connected', false)->assertJsonPath('data.open', false);
        $players = collect($r->json('data.players'))->keyBy('name');
        $this->assertTrue($players['Mario Rossi']['called_up']);
        $this->assertTrue($players['Mario Rossi']['selected'], 'senza XFive si parte dai convocati');
        $this->assertSame('7', $players['Mario Rossi']['suggested_number']);
        $this->assertFalse($players['Luca Bianchi']['selected']);
    }

    public function test_open_sheet_lists_xfive_players_matched_by_id_and_by_name_with_past_numbers(): void
    {
        $mario = $this->player('Mario', 'Rossi', ['xfive_admin_id' => 62597]);
        $luca = $this->player('Luca', 'Bianchi', ['birth_date' => '1996-02-28']);
        $this->fakeXfiveSheet(players: [
            [62597, 'Rossi Mario', '28/01/1999', false, '4'],
            [62605, 'Bianchi Luca', '28/02/1996', false, ''],
            [62631, 'Sconosciuto Tizio', '01/01/2000', false, '9'],
        ]);
        $this->connect()->assertOk();
        MatchCallup::create(['match_id' => $this->game->id, 'player_id' => $luca->id]);

        $old = OfficialSheet::create(['match_id' => $this->game->id, 'user_id' => $this->user->id, 'sent_at' => now()->subWeek()]);
        $old->players()->create(['player_id' => $mario->id, 'xfive_admin_id' => 62597, 'name' => 'Mario Rossi', 'number' => '10']);

        $r = $this->getJson("/api/v1/matches/{$this->game->id}/sheet")->assertOk();

        $r->assertJsonPath('data.open', true)->assertJsonPath('data.connected', true);
        $by = collect($r->json('data.players'))->keyBy('xfive_id');
        $this->assertSame($mario->id, $by[62597]['player_id']);
        $this->assertSame('10', $by[62597]['suggested_number'], 'il numero delle distinte passate vince su quello che ha XFive');
        $this->assertSame([['number' => '10', 'count' => 1, 'last_used_at' => $old->sent_at->toIso8601String()]], $by[62597]['history']);
        $this->assertSame($luca->id, $by[62605]['player_id'], 'trovato per nome e data di nascita');
        $this->assertTrue($by[62605]['selected'], 'nessuno scelto su XFive: si parte dai convocati');
        $this->assertNull($by[62631]['player_id']);
        $this->assertSame('9', $by[62631]['suggested_number'], 'senza storico, il numero che ha già XFive');
        $this->assertFalse($by[62597]['selected']);
    }

    public function test_when_xfive_does_not_open_the_sheet_the_reason_is_shown(): void
    {
        $this->player('Mario', 'Rossi');
        $this->fakeXfiveSheet(open: [14602 => 3604]); // la nostra partita non è fra quelle apribili
        $this->connect()->assertOk();

        $r = $this->getJson("/api/v1/matches/{$this->game->id}/sheet")->assertOk();

        $r->assertJsonPath('data.open', false)->assertJsonPath('data.connected', true);
        $this->assertStringContainsString('5 minuti prima', $r->json('data.reason'));
        $this->assertCount(1, $r->json('data.players'), 'si vedono comunque i giocatori con i numeri suggeriti');
    }

    public function test_save_posts_the_form_like_the_browser_and_remembers_the_numbers(): void
    {
        $mario = $this->player('Mario', 'Rossi', ['xfive_admin_id' => 62597]);
        $this->fakeXfiveSheet(players: [
            [62597, 'Rossi Mario', '28/01/1999', false, '4'],
            [62605, 'Bianchi Luca', '28/02/1996', false, ''],
            [62607, 'Verdi Paolo', '01/03/1997', false, ''],
        ]);
        $this->connect()->assertOk();

        $r = $this->postJson("/api/v1/matches/{$this->game->id}/sheet", [
            'players' => [['xfive_id' => 62597, 'number' => '10'], ['xfive_id' => 62605, 'number' => 'd']],
            'note' => 'Ritrovo alle 19:30',
        ])->assertOk();

        $r->assertJsonPath('data.message', 'Distinta salvata correttamente');

        Http::assertSent(function (Request $request) {
            if (($request['op'] ?? null) != 3) {
                return false;
            }

            return $request['mid'] == 14584 && $request['gtid'] == 3604
                && $request['players'] == [62597, 62605]
                && $request['number62597'] === '10' && $request['number62605'] === 'D'
                && ! isset($request['number62607'])
                && $request['note'] === 'Ritrovo alle 19:30';
        });

        $sheet = OfficialSheet::with('players')->firstOrFail();
        $this->assertSame($this->game->id, $sheet->match_id);
        $this->assertSame($this->user->id, $sheet->user_id);
        $this->assertEqualsCanonicalizing(['10', 'D'], $sheet->players->pluck('number')->all());
        $this->assertSame($mario->id, $sheet->players->firstWhere('number', '10')->player_id);
        $this->assertNull($sheet->players->firstWhere('number', 'D')->player_id, 'giocatore non ancora abbinato alla nostra rosa');

        // la volta dopo il numero di Mario si suggerisce da solo
        $numbers = collect($this->getJson('/api/v1/sheet-numbers')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame('10', $numbers['Mario Rossi']['last_number']);
    }

    public function test_save_refuses_duplicate_numbers_unknown_players_and_empty_selection_before_sending(): void
    {
        $this->fakeXfiveSheet(players: [[62597, 'Rossi Mario', '28/01/1999', false, ''], [62605, 'Bianchi Luca', '28/02/1996', false, '']]);
        $this->connect()->assertOk();
        $url = "/api/v1/matches/{$this->game->id}/sheet";

        $this->postJson($url, ['players' => [['xfive_id' => 62597, 'number' => '10'], ['xfive_id' => 62605, 'number' => '10']]])
            ->assertStatus(422)->assertJsonPath('state', 'invalid');
        $this->postJson($url, ['players' => [['xfive_id' => 99999, 'number' => '3']]])->assertStatus(422)->assertJsonPath('state', 'invalid');
        $this->postJson($url, ['players' => [['xfive_id' => 62597, 'number' => 'ABCD']]])->assertStatus(422);
        $this->postJson($url, ['players' => []])->assertStatus(422);

        Http::assertNotSent(fn (Request $r) => ($r['op'] ?? null) == 3);
        $this->assertSame(0, OfficialSheet::count());
    }

    public function test_a_server_error_from_xfive_says_which_step_failed(): void
    {
        $this->player('Mario', 'Rossi');
        $this->fakeXfiveSheet(players: [[62597, 'Rossi Mario', '28/01/1999', false, '']]);
        $this->connect()->assertOk();

        // XFive risponde 500 all'elenco partite: l'app lo dice con il codice e il passaggio, senza mostrare nulla della risposta
        $this->scheduleStatus = 503;

        $r = $this->getJson("/api/v1/matches/{$this->game->id}/sheet")->assertStatus(502);
        $this->assertStringContainsString('503', $r->json('message'));
        $this->assertStringContainsString("l'elenco delle partite", $r->json('message'));
    }

    public function test_xfive_rejection_is_shown_and_nothing_is_remembered(): void
    {
        $this->fakeXfiveSheet(players: [[62597, 'Rossi Mario', '28/01/1999', false, '']], saveResponse: (string) json_encode(['errors' => '<b>Distinta</b> non modificabile: gara iniziata']));
        $this->connect()->assertOk();

        $this->postJson("/api/v1/matches/{$this->game->id}/sheet", ['players' => [['xfive_id' => 62597, 'number' => '4']]])
            ->assertStatus(422)->assertJsonPath('state', 'rejected')->assertJsonPath('message', 'Distinta non modificabile: gara iniziata');

        $this->assertSame(0, OfficialSheet::count());
    }

    public function test_expired_xfive_session_is_dropped_and_the_app_is_asked_to_connect_again(): void
    {
        $this->player('Mario', 'Rossi');
        $this->fakeXfiveSheet(players: [[62597, 'Rossi Mario', '28/01/1999', false, '']]);
        $this->connect()->assertOk();

        $this->expired = true; // XFive ha chiuso la sessione: risponde con la pagina del sito invece del JSON

        $this->getJson("/api/v1/matches/{$this->game->id}/sheet")->assertOk()
            ->assertJsonPath('data.connected', false)->assertJsonPath('data.open', false);
        $this->assertSame(0, XfiveSession::count());

        $this->postJson("/api/v1/matches/{$this->game->id}/sheet", ['players' => [['xfive_id' => 1, 'number' => '4']]])
            ->assertStatus(409)->assertJsonPath('state', 'not_connected');
    }

    // ---------- id XFive dei giocatori ----------

    public function test_connecting_assigns_the_xfive_roster_ids_and_changes_nothing_else(): void
    {
        $mario = $this->player('Mario', 'Rossi', ['in_squad_list' => false, 'registration_status' => 'none']);
        $luca = $this->player('Luca', 'Bianchi');
        $alone = $this->player('Giorgio', 'Verdi');
        $this->fakeXfiveSheet();

        $r = $this->connect()->assertOk();

        $r->assertJsonPath('data.link.linked', 2)->assertJsonPath('data.link_error', null);
        $this->assertSame(9001, $mario->refresh()->xfive_admin_id);
        $this->assertSame(9002, $luca->refresh()->xfive_admin_id);
        $this->assertNull($alone->refresh()->xfive_admin_id);
        $this->assertContains('Giorgio Verdi', $r->json('data.link.missing_on_xfive'));
        $this->assertCount(3, $r->json('data.link.unmatched'), 'gli altri tre di XFive non sono nella nostra rosa');
        $this->assertFalse((bool) $mario->in_squad_list, 'solo gli id: Squad List, certificati e tesseramenti non si toccano');
        $this->assertNull($mario->medical_cert_expires_on);

        // rifatto, non cambia niente e non si duplica
        $this->postJson('/api/v1/xfive-session/link-players')->assertOk()->assertJsonPath('data.linked', 0)->assertJsonPath('data.already', 2);
    }

    public function test_linking_players_needs_the_xfive_connection(): void
    {
        $this->fakeXfiveSheet();

        $this->postJson('/api/v1/xfive-session/link-players')->assertStatus(409)->assertJsonPath('state', 'not_connected');
    }

    public function test_opening_the_sheet_remembers_each_players_sheet_id(): void
    {
        $mario = $this->player('Mario', 'Rossi', ['birth_date' => '1999-01-28']);
        $luca = $this->player('Luca', 'Bianchi');
        $this->fakeXfiveSheet(players: [
            [62597, 'Rossi Mario', '28/01/1999', false, ''],
            [62605, 'Bianchi Luca', '28/02/1996', false, ''],
        ]);
        $this->connect()->assertOk();

        $this->getJson("/api/v1/matches/{$this->game->id}/sheet")->assertOk()->assertJsonPath('data.open', true);

        $this->assertSame(62597, $mario->refresh()->xfive_sheet_id);
        $this->assertSame(62605, $luca->refresh()->xfive_sheet_id);

        // dopo, anche con XFive chiuso, la scheda sa a chi appartiene ogni giocatore
        $this->deleteJson('/api/v1/xfive-session')->assertOk();
        $r = $this->getJson("/api/v1/matches/{$this->game->id}/sheet")->assertOk();
        $this->assertSame([62605, 62597], collect($r->json('data.players'))->sortBy('name')->pluck('xfive_id')->values()->all());
    }

    // ---------- distinta sul sito pubblico e PDF ----------

    private function sendSheet(): void
    {
        $this->fakeXfiveSheet(players: [
            [62597, 'Rossi Mario', '28/01/1999', false, ''],
            [62605, 'Bianchi Luca', '28/02/1996', false, ''],
            [62607, 'Verdi Paolo', '01/03/1997', false, ''],
            [62609, 'Neri Carlo', '05/05/1995', false, ''],
        ]);
        $this->connect()->assertOk();
        $this->postJson("/api/v1/matches/{$this->game->id}/sheet", ['players' => [
            ['xfive_id' => 62597, 'number' => '10'],
            ['xfive_id' => 62605, 'number' => '9'],
            ['xfive_id' => 62607, 'number' => 'D'],
            ['xfive_id' => 62609, 'number' => '2'],
        ]])->assertOk();
    }

    public function test_the_sheet_can_be_published_only_after_it_was_sent(): void
    {
        $this->patchJson("/api/v1/matches/{$this->game->id}", ['sheet_published' => true])
            ->assertStatus(422)->assertJsonValidationErrors('sheet_published');

        $this->sendSheet();

        $detail = $this->getJson("/api/v1/matches/{$this->game->id}")->assertOk();
        $detail->assertJsonPath('data.sheet_published', false);
        $this->assertNotNull($detail->json('data.sheet_sent_at'));

        $this->patchJson("/api/v1/matches/{$this->game->id}", ['sheet_published' => true])
            ->assertOk()->assertJsonPath('data.sheet_published', true);
    }

    public function test_the_public_page_shows_the_published_sheet_in_number_order_instead_of_the_callups(): void
    {
        $mario = $this->player('Mario', 'Rossi', ['xfive_sheet_id' => 62597]);
        MatchCallup::create(['match_id' => $this->game->id, 'player_id' => $mario->id]);
        $this->game->update(['callups_published' => true]);
        $this->sendSheet();

        // non pubblicata: sul sito ci sono ancora i convocati e nessuna traccia della distinta
        $before = $this->getJson("/api/v1/public/matches/{$this->game->id}")->assertOk();
        $this->assertNull($before->json('data.sheet'));
        $this->assertCount(1, $before->json('data.callups'));

        $this->patchJson("/api/v1/matches/{$this->game->id}", ['sheet_published' => true])->assertOk();

        $after = $this->getJson("/api/v1/public/matches/{$this->game->id}")->assertOk();
        $this->assertNull($after->json('data.callups'), 'la distinta prende il posto dei convocati');
        $this->assertSame(['2', '9', '10', 'D'], collect($after->json('data.sheet.players'))->pluck('number')->all(), 'prima i numeri in ordine, poi le lettere');
        $ten = $after->json('data.sheet.players.2');
        $this->assertSame($mario->id, $ten['player']['id']);
        $this->assertSame('Mario Rossi', $ten['name']);
        $this->assertNull($after->json('data.sheet.players.0.player'), 'chi non è abbinato alla rosa si vede solo con il nome di XFive');
        $this->assertSame('Neri Carlo', $after->json('data.sheet.players.0.name'));

        // nascosta di nuovo, tornano i convocati
        $this->patchJson("/api/v1/matches/{$this->game->id}", ['sheet_published' => false])->assertOk();
        $back = $this->getJson("/api/v1/public/matches/{$this->game->id}")->assertOk();
        $this->assertNull($back->json('data.sheet'));
        $this->assertCount(1, $back->json('data.callups'));
    }

    public function test_the_pdf_link_is_for_the_staff_and_only_when_a_sheet_was_sent(): void
    {
        $this->getJson("/api/v1/matches/{$this->game->id}/sheet-pdf")->assertNotFound();

        $this->sendSheet();

        $this->getJson("/api/v1/matches/{$this->game->id}/sheet-pdf")->assertOk()
            ->assertJsonPath('data.url', 'https://www.xfivesport.it/pdfTeamsSchedule.php?mid=14584&gtid=3604');

        // il sito pubblico non contiene mai il link
        $this->assertStringNotContainsString('pdfTeamsSchedule', (string) $this->getJson("/api/v1/public/matches/{$this->game->id}")->getContent());
    }

    public function test_a_sheet_filled_in_directly_on_xfive_makes_the_pdf_reachable_once_the_staff_opens_it(): void
    {
        $this->player('Mario', 'Rossi', ['xfive_admin_id' => 62597]);
        // già compilata su XFive (fuori da qui): c'è un giocatore scelto con il suo numero
        $this->fakeXfiveSheet(players: [[62597, 'Rossi Mario', '28/01/1999', true, '4']]);
        $this->connect()->assertOk();

        // finché nessuno apre la scheda non si sa niente: niente PDF, e niente chiamate fatte per cercarlo
        $before = count($this->calls);
        $this->getJson("/api/v1/matches/{$this->game->id}/sheet-pdf")->assertNotFound();
        $this->assertSame($before, count($this->calls), 'la richiesta del PDF non contatta XFive');

        $this->getJson("/api/v1/matches/{$this->game->id}/sheet")->assertOk()
            ->assertJsonPath('data.pdf_url', 'https://www.xfivesport.it/pdfTeamsSchedule.php?mid=14584&gtid=3604');

        $this->getJson("/api/v1/matches/{$this->game->id}/sheet-pdf")->assertOk()
            ->assertJsonPath('data.url', 'https://www.xfivesport.it/pdfTeamsSchedule.php?mid=14584&gtid=3604');
        $this->assertSame(3604, $this->game->fresh()->xfive_gtid);
    }

    public function test_opening_an_empty_sheet_does_not_make_up_a_pdf(): void
    {
        $this->player('Mario', 'Rossi', ['xfive_admin_id' => 62597]);
        $this->fakeXfiveSheet(players: [[62597, 'Rossi Mario', '28/01/1999', false, '']]);
        $this->connect()->assertOk();

        $this->getJson("/api/v1/matches/{$this->game->id}/sheet")->assertOk()->assertJsonPath('data.pdf_url', null);
        $this->getJson("/api/v1/matches/{$this->game->id}/sheet-pdf")->assertNotFound();
    }
}
