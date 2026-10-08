<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\SyncRun;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AdminRosterFixtures;
use Tests\TestCase;

/** Lettura dell'area amministrazione di XFive: accesso, rosa, tesseramenti, certificati, Squad List. XFive è simulato. */
class XfiveAdminSyncTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password-segreta-di-prova-9';

    private Team $own;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'amir.xfive.throttle_ms' => 0,
            'amir.xfive.base_url' => 'https://www.xfivesport.it',
            'amir.sync.inline' => true,
            'amir.xfive_admin.enabled' => true,
            'amir.xfive_admin.email' => 'capo@example.com',
            'amir.xfive_admin.password' => self::PASSWORD,
            'amir.xfive_admin.cooldown_minutes' => 120,
        ]);
        $this->own = Team::create(['name' => 'AMIR COSTRUZIONI', 'short_name' => 'AMIR', 'xfive_club_id' => 159, 'is_own' => true, 'format' => 8]);
        Sanctum::actingAs(User::factory()->create());
    }

    /**
     * XFive simulato: dopo l'accesso (login.php con le credenziali giuste) le pagine mostrano «Esci». La pagina della rosa ha la
     * tabella vuota e l'elenco arriva dalla richiesta a parte (team.php), come sul sito vero.
     */
    private function fakeAdminXfive(bool $acceptLogin = true, ?string $ajax = null): void
    {
        $loggedIn = false;
        $ajax ??= AdminRosterFixtures::ajaxJson();

        Http::fake(function (Request $request) use (&$loggedIn, $acceptLogin, $ajax) {
            $url = $request->url();

            if (str_ends_with($url, '/login.php')) {
                $loggedIn = $acceptLogin && ($request['mail'] ?? null) === 'capo@example.com' && ($request['password'] ?? null) === self::PASSWORD;

                return Http::response('', 302, ['Location' => 'https://www.xfivesport.it/']);
            }

            if (! $loggedIn) {
                return Http::response('<html><body><a onclick="suggestionFeedback(\'login\')">Accedi</a></body></html>');
            }

            if (str_ends_with($url, '/manage_tournament/team.php')) {
                return Http::response($ajax, 200, ['Content-Type' => 'application/json']);
            }

            return Http::response(str_contains($url, 'sk=team')
                ? AdminRosterFixtures::shellPage()
                : '<html><body>Amministrazione <a href="https://www.xfivesport.it/logout.php">Esci</a></body></html>');
        });
    }

    private function player(string $first, string $last, array $extra = []): Player
    {
        return Player::create(['team_id' => $this->own->id, 'first_name' => $first, 'last_name' => $last] + $extra);
    }

    private function runAdmin()
    {
        return $this->postJson('/api/v1/sync/xfive', ['scope' => 'admin']);
    }

    public function test_it_does_nothing_and_contacts_nobody_while_it_is_switched_off(): void
    {
        Http::fake();

        config(['amir.xfive_admin.enabled' => false]);
        $this->runAdmin()->assertOk()->assertJsonPath('data.status', 'ok')->assertJsonPath('data.stats.disabled', 1);

        config(['amir.xfive_admin.enabled' => true, 'amir.xfive_admin.password' => null]);
        $this->runAdmin()->assertOk()->assertJsonPath('data.stats.disabled', 1);

        Http::assertNothingSent();
    }

    public function test_a_full_read_updates_the_players_and_sends_the_credentials_only_to_the_login(): void
    {
        $this->fakeAdminXfive();
        $rossi = $this->player('Mario', 'Rossi', ['in_squad_list' => false]);      // XFive: Squad List, certificato scaduto
        $bianchi = $this->player('Luca', 'Bianchi', ['in_squad_list' => true]);    // XFive: non in Squad List
        $deLuca = $this->player('Paolo', 'De Luca', ['in_squad_list' => true, 'medical_cert_expires_on' => '2027-03-15']); // già a posto
        $neri = $this->player('Andrea', 'Neri', ['medical_cert_expires_on' => '2026-12-31']); // XFive non ha il certificato: resta il nostro
        $assente = $this->player('Carlo', 'Verdi'); // non c'è su XFive

        $response = $this->runAdmin()->assertOk()->assertJsonPath('data.status', 'ok');

        $stats = $response->json('data.stats');
        $this->assertSame(5, $stats['rows']);
        $this->assertSame(4, $stats['matched']);
        $this->assertSame(4, $stats['new_links']);
        $this->assertSame(1, $stats['unmatched'], 'Gialli Marco non è tra i nostri giocatori: si conta e basta');
        $this->assertSame(1, $stats['missing_on_xfive']);
        $this->assertSame(2, $stats['squad_list_changes'], 'Rossi entra in Squad List, Bianchi esce');
        $this->assertSame(1, $stats['certificate_changes'], 'solo Rossi: quello di De Luca era già uguale');
        $this->assertSame(5, Player::count(), 'nessun giocatore creato o cancellato');

        $rossi->refresh();
        $this->assertSame(9001, $rossi->xfive_admin_id);
        $this->assertTrue($rossi->in_squad_list);
        $this->assertSame('2024-11-27', $rossi->medical_cert_expires_on->toDateString());
        $this->assertSame('1995-01-14', $rossi->birth_date->toDateString(), 'la data di nascita mancante si completa');
        $this->assertSame('portiere', $rossi->role);
        $this->assertSame('SQUAD LIST (riservato organizzazione)', $rossi->xfive_membership['type']);
        $this->assertSame(1, $rossi->xfive_membership['documents']);
        $this->assertNotNull($rossi->xfive_admin_synced_at);

        $this->assertFalse($bianchi->refresh()->in_squad_list);
        $this->assertTrue($deLuca->refresh()->in_squad_list);
        $this->assertSame('2026-12-31', $neri->refresh()->medical_cert_expires_on->toDateString(), 'se XFive non ha una data, la nostra resta');
        $this->assertSame('pending', $neri->xfive_membership['status']);
        $this->assertNull($assente->refresh()->xfive_admin_id);

        // la password parte una sola volta, verso il login di XFive, in HTTPS, e non compare in nessun altro indirizzo né nella risposta
        Http::assertSent(fn (Request $r) => $r->url() === 'https://www.xfivesport.it/login.php' && $r['password'] === self::PASSWORD && $r['mail'] === 'capo@example.com');
        Http::assertSentCount(5); // accesso, ritorno alla pagina iniziale (reindirizzamento), pagina di controllo, pagina della rosa, elenco
        $leaks = [];
        foreach (Http::recorded() as [$r]) {
            if (! str_ends_with($r->url(), '/login.php') && str_contains($r->url().$r->body(), self::PASSWORD)) {
                $leaks[] = $r->method().' '.$r->url();
            }
        }
        $this->assertSame([], $leaks, 'la password deve stare solo nella richiesta di accesso');
        // l'elenco si chiede come fa la pagina: stessa richiesta (op=1 e il club), segnalata come AJAX, con la pagina di partenza
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/manage_tournament/team.php') && $r->method() === 'POST'
            && (int) $r['op'] === 1 && (int) $r['tmid'] === 159 && (int) $r['length'] === -1
            && $r->hasHeader('X-Requested-With', 'XMLHttpRequest') && str_contains($r->header('Referer')[0] ?? '', 'sk=team'));
        $this->assertStringNotContainsString(self::PASSWORD, $response->getContent());
        $this->assertStringNotContainsString(self::PASSWORD, (string) SyncRun::latest('id')->first()->toJson());
    }

    public function test_reading_again_changes_nothing_that_is_already_right(): void
    {
        $this->fakeAdminXfive();
        $this->player('Mario', 'Rossi');
        $this->player('Luca', 'Bianchi');

        $this->runAdmin()->assertOk();
        $second = $this->runAdmin()->assertOk()->json('data.stats');

        $this->assertSame(0, $second['new_links']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(0, $second['squad_list_changes']);
        $this->assertSame(0, $second['certificate_changes']);
    }

    public function test_a_different_birth_date_is_reported_not_overwritten(): void
    {
        $this->fakeAdminXfive();
        $rossi = $this->player('Mario', 'Rossi', ['birth_date' => '1990-06-06']);

        $this->runAdmin()->assertOk()->assertJsonPath('data.stats.birth_mismatch', 1);

        $this->assertSame('1990-06-06', $rossi->refresh()->birth_date->toDateString());
    }

    public function test_two_players_with_the_same_name_are_told_apart_by_the_birth_date_or_left_alone(): void
    {
        $this->fakeAdminXfive();
        $giusto = $this->player('Mario', 'Rossi', ['birth_date' => '1995-01-14']);
        $altro = $this->player('Rossi', 'Mario', ['birth_date' => '1980-02-02']);

        $this->runAdmin()->assertOk()->assertJsonPath('data.stats.new_links', 1);
        $this->assertSame(9001, $giusto->refresh()->xfive_admin_id);
        $this->assertNull($altro->refresh()->xfive_admin_id);

        // senza date di nascita non si può scegliere: nessuno dei due viene toccato
        Player::query()->update(['xfive_admin_id' => null, 'birth_date' => null]);
        $this->runAdmin()->assertOk()->assertJsonPath('data.stats.ambiguous', 1);
        $this->assertNull($giusto->refresh()->xfive_admin_id);
        $this->assertNull($altro->refresh()->xfive_admin_id);
    }

    public function test_a_refused_login_is_reported_and_xfive_is_left_alone_for_a_while(): void
    {
        config(['amir.xfive_admin.password' => 'sbagliata-di-sicuro-1']);
        $this->fakeAdminXfive();
        $this->player('Mario', 'Rossi');

        $this->runAdmin()->assertOk()
            ->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.error', fn ($e) => str_contains($e, 'rifiutato') && ! str_contains($e, 'sbagliata-di-sicuro-1'));

        $sent = count(Http::recorded());
        $this->postJson('/api/v1/xfive-admin/check')->assertOk()->assertJsonPath('data.state', 'blocked');
        $this->assertSame($sent, count(Http::recorded()), 'durante la pausa non parte nessuna richiesta a XFive');
    }

    public function test_an_empty_list_changes_nothing_and_says_so(): void
    {
        $this->fakeAdminXfive(ajax: '{"draw":1,"recordsTotal":0,"recordsFiltered":0,"data":[]}');
        $rossi = $this->player('Mario', 'Rossi', ['in_squad_list' => true]);

        $this->runAdmin()->assertOk()
            ->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.error', fn ($e) => str_contains($e, 'struttura'));

        $this->assertTrue($rossi->refresh()->in_squad_list);
        $this->assertNull($rossi->xfive_admin_id);
    }

    public function test_an_answer_that_is_not_the_list_changes_nothing_and_says_so(): void
    {
        $this->fakeAdminXfive(ajax: '<html><body>Sessione scaduta</body></html>');
        $rossi = $this->player('Mario', 'Rossi', ['in_squad_list' => true]);

        $this->runAdmin()->assertOk()
            ->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.error', fn ($e) => str_contains($e, 'formato atteso'));

        $this->assertTrue($rossi->refresh()->in_squad_list);
    }

    public function test_the_credentials_are_never_sent_over_plain_http(): void
    {
        config(['amir.xfive.base_url' => 'http://www.xfivesport.it']);
        Http::fake();

        $this->postJson('/api/v1/xfive-admin/check')->assertOk()->assertJsonPath('data.ok', false);

        Http::assertNothingSent();
    }

    public function test_the_status_never_shows_the_credentials_and_the_check_reports_the_outcome(): void
    {
        $this->fakeAdminXfive();

        $status = $this->getJson('/api/v1/xfive-admin')->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.last_run', null);
        $this->assertStringNotContainsString(self::PASSWORD, $status->getContent());
        $this->assertStringNotContainsString('capo@example.com', $status->getContent());

        $this->postJson('/api/v1/xfive-admin/check')->assertOk()->assertJsonPath('data.ok', true)->assertJsonPath('data.state', 'ok');

        config(['amir.xfive_admin.password' => null]);
        $this->getJson('/api/v1/xfive-admin')->assertJsonPath('data.configured', false)->assertJsonPath('data.enabled', false);
        $this->postJson('/api/v1/xfive-admin/check')->assertOk()->assertJsonPath('data.state', 'not_configured');
    }
}
