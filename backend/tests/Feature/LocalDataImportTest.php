<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Payment;
use App\Models\Player;
use App\Models\PlayerCharge;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Le info dei giocatori e i pagamenti del gestionale sul computer si uniscono ai dati online senza sostituire nulla. */
class LocalDataImportTest extends TestCase
{
    use RefreshDatabase;

    private Team $own;

    protected function setUp(): void
    {
        parent::setUp();

        $this->own = Team::create(['name' => 'AMIR COSTRUZIONI', 'short_name' => 'AMIR', 'xfive_club_id' => 159, 'is_own' => true, 'format' => 8]);
    }

    /** Un giocatore già online (di solito importato da XFive). */
    private function online(string $first, string $last, array $extra = []): Player
    {
        return Player::create(['team_id' => $this->own->id, 'first_name' => $first, 'last_name' => $last, 'is_active' => true] + $extra);
    }

    /** Il database SQLite del gestionale locale, riempito dalla funzione ricevuta: restituisce il contenuto del file. */
    private function localDatabase(callable $fill): string
    {
        $dir = sys_get_temp_dir().'/amir-local-'.bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => $dir.'/database.sqlite', 'prefix' => '', 'foreign_key_constraints' => true]]);
            touch($dir.'/database.sqlite');
            Artisan::call('migrate', ['--database' => 'legacy', '--force' => true]);

            $legacy = DB::connection('legacy');
            $legacy->table('teams')->insert(['id' => 3, 'name' => 'AMIR COSTRUZIONI', 'short_name' => 'AMIR', 'xfive_club_id' => 159, 'is_own' => 1, 'format' => 8]);
            $fill($legacy);
            DB::purge('legacy');

            return (string) file_get_contents($dir.'/database.sqlite');
        } finally {
            DB::purge('legacy');
            File::deleteDirectory($dir);
        }
    }

    private function localPlayer($legacy, int $id, string $first, string $last, array $extra = []): void
    {
        $legacy->table('players')->insert($extra + [
            'id' => $id, 'team_id' => 3, 'first_name' => $first, 'last_name' => $last, 'is_active' => 1,
            'access_token' => str_pad((string) $id, 64, 'x'),
        ]);
    }

    private function upload(string $contents, string $name = 'database.sqlite', bool $loggedIn = true)
    {
        if ($loggedIn) {
            Sanctum::actingAs(User::factory()->create());
        }

        return $this->post('/api/v1/backup/import-local', [
            'file' => UploadedFile::fake()->createWithContent($name, $contents),
        ], ['Accept' => 'application/json']);
    }

    public function test_it_completes_what_is_empty_online_and_never_touches_what_comes_from_xfive(): void
    {
        $rossi = $this->online('Mario', 'Rossi', [
            'role' => 'portiere', 'in_squad_list' => true, 'registration_status' => 'approved', 'medical_cert_expires_on' => '2027-03-01',
            'xfive_admin_id' => 9001,
        ]);

        $file = $this->localDatabase(function ($legacy) {
            $this->localPlayer($legacy, 11, 'Mario', 'Rossi', [
                'nickname' => 'Rosso', 'shirt_number' => '9', 'shirt_number_red' => '9', 'phone' => '333 000 0000', 'email' => 'mario@example.com',
                'notes' => 'Capitano', 'role' => 'attaccante', 'in_squad_list' => 0, 'registration_status' => 'none',
                'medical_cert_expires_on' => '2026-01-01', 'birth_date' => '1995-01-14 00:00:00', 'xfive_profile' => '{"gol":3}',
                'scout_text' => 'Veloce sulla fascia', 'scout_source' => 'ia',
            ]);
        });

        $stats = $this->upload($file)->assertOk()->json('data');

        $rossi->refresh();
        $this->assertSame('Rosso', $rossi->nickname);
        $this->assertSame('9', (string) $rossi->shirt_number);
        $this->assertSame('9', (string) $rossi->shirt_number_red);
        $this->assertSame('333 000 0000', $rossi->phone);
        $this->assertSame('mario@example.com', $rossi->email);
        $this->assertSame('Capitano', $rossi->notes);
        $this->assertSame('1995-01-14', $rossi->birth_date->toDateString());
        $this->assertSame(['gol' => 3], $rossi->xfive_profile);
        $this->assertSame('Veloce sulla fascia', $rossi->scout_text);

        // quello che XFive sa meglio di noi resta com'è
        $this->assertSame('portiere', $rossi->role, 'il ruolo online c\'era già');
        $this->assertTrue($rossi->in_squad_list);
        $this->assertSame('approved', $rossi->registration_status);
        $this->assertSame('2027-03-01', $rossi->medical_cert_expires_on->toDateString());
        $this->assertSame(9001, $rossi->xfive_admin_id);

        $this->assertSame(1, $stats['players_matched']);
        $this->assertSame(1, $stats['players_filled']);
        $this->assertSame(10, $stats['fields_filled']);
        $this->assertSame(1, Player::count(), 'nessun giocatore nuovo: li crea solo l\'importazione da XFive');
    }

    public function test_names_match_in_any_word_order_ignoring_accents_and_case(): void
    {
        $deLuca = $this->online('Paolo', 'De Luca');
        $dandrea = $this->online('Nicolò', 'D\'Andrea');

        $file = $this->localDatabase(function ($legacy) {
            $this->localPlayer($legacy, 1, 'Paolo De', 'LUCA', ['phone' => '111']);
            $this->localPlayer($legacy, 2, 'Nicolo', 'D Andrea', ['phone' => '222']);
        });

        $stats = $this->upload($file)->assertOk()->json('data');

        $this->assertSame(2, $stats['players_matched']);
        $this->assertSame('111', $deLuca->refresh()->phone);
        $this->assertSame('222', $dandrea->refresh()->phone);
    }

    public function test_homonyms_are_told_apart_by_birth_date_or_left_alone(): void
    {
        $a = $this->online('Luca', 'Bianchi', ['birth_date' => '1990-05-05']);
        $b = $this->online('Luca', 'Bianchi', ['birth_date' => '1998-08-08']);

        $file = $this->localDatabase(function ($legacy) {
            $this->localPlayer($legacy, 1, 'Luca', 'Bianchi', ['phone' => '111', 'birth_date' => '1998-08-08']);
            $this->localPlayer($legacy, 2, 'Luca', 'Bianchi', ['phone' => '222']); // senza data: non si sa quale dei due sia
        });

        $stats = $this->upload($file)->assertOk()->json('data');

        $this->assertSame('111', $b->refresh()->phone);
        $this->assertNull($a->refresh()->phone, 'nel dubbio non si assegna a nessuno');
        $this->assertSame(1, $stats['players_matched']);
        $this->assertSame(1, $stats['players_ambiguous']);
    }

    public function test_two_local_records_for_the_same_online_player_only_the_one_still_playing_counts(): void
    {
        $rossi = $this->online('Mario', 'Rossi');

        $file = $this->localDatabase(function ($legacy) {
            $this->localPlayer($legacy, 1, 'Mario', 'Rossi', ['is_active' => 0, 'phone' => 'vecchio']);
            $this->localPlayer($legacy, 2, 'Mario', 'Rossi', ['phone' => 'nuovo']);
        });

        $stats = $this->upload($file)->assertOk()->json('data');

        $this->assertSame('nuovo', $rossi->refresh()->phone);
        $this->assertSame(1, $stats['players_matched']);
        $this->assertSame(1, $stats['players_ambiguous']);
    }

    public function test_only_active_local_players_missing_online_are_reported(): void
    {
        $this->online('Mario', 'Rossi');

        $file = $this->localDatabase(function ($legacy) {
            $this->localPlayer($legacy, 1, 'Mario', 'Rossi');
            $this->localPlayer($legacy, 2, 'Gianni', 'Assente');
            $this->localPlayer($legacy, 3, 'Ex', 'Giocatore', ['is_active' => 0]);
        });

        $stats = $this->upload($file)->assertOk()->json('data');

        $this->assertSame(1, $stats['players_not_found'], 'chi non gioca più non è un problema');
        $this->assertSame(1, Player::count());
    }

    public function test_a_profile_already_used_by_someone_else_is_not_copied(): void
    {
        $other = $this->online('Altro', 'Giocatore', ['xfive_person_id' => 5555]);
        $rossi = $this->online('Mario', 'Rossi');

        $file = $this->localDatabase(function ($legacy) {
            $this->localPlayer($legacy, 1, 'Mario', 'Rossi', ['xfive_person_id' => 5555, 'phone' => '333']);
        });

        $this->upload($file)->assertOk();

        $this->assertNull($rossi->refresh()->xfive_person_id);
        $this->assertSame(5555, $other->refresh()->xfive_person_id);
        $this->assertSame('333', $rossi->phone, 'il resto si copia comunque');
    }

    public function test_charges_quotes_and_payments_come_over_and_the_totals_match(): void
    {
        $rossi = $this->online('Mario', 'Rossi');
        $neri = $this->online('Carlo', 'Neri');

        $file = $this->localDatabase(function ($legacy) {
            $this->localPlayer($legacy, 11, 'Mario', 'Rossi');
            $this->localPlayer($legacy, 12, 'Carlo', 'Neri');
            $this->localPlayer($legacy, 13, 'Gianni', 'Assente');

            $legacy->table('charges')->insert(['id' => 1, 'team_id' => 3, 'title' => 'STAGIONE 26/27', 'kind' => 'quota', 'amount_cents' => 1250, 'due_on' => '2026-11-20 00:00:00', 'season' => '26/27']);
            $legacy->table('player_charges')->insert([
                ['id' => 1, 'charge_id' => 1, 'player_id' => 11, 'amount_cents' => 1250],
                ['id' => 2, 'charge_id' => 1, 'player_id' => 12, 'amount_cents' => 1000],
                ['id' => 3, 'charge_id' => 1, 'player_id' => 13, 'amount_cents' => 1250],
            ]);
            $legacy->table('payments')->insert([
                ['id' => 1, 'player_charge_id' => 1, 'amount_cents' => 1250, 'method' => 'contanti', 'paid_at' => '2026-10-01 00:00:00', 'note' => 'saldo'],
                ['id' => 2, 'player_charge_id' => 2, 'amount_cents' => 500, 'method' => 'satispay', 'paid_at' => '2026-10-03 00:00:00', 'note' => null],
                ['id' => 3, 'player_charge_id' => 3, 'amount_cents' => 1250, 'method' => 'contanti', 'paid_at' => '2026-10-04 00:00:00', 'note' => null],
            ]);
        });

        $stats = $this->upload($file)->assertOk()->json('data');

        $this->assertSame(1, $stats['charges_created']);
        $this->assertSame(2, $stats['player_charges_created']);
        $this->assertSame(2, $stats['payments_created']);
        $this->assertSame(2, $stats['finance_skipped'], 'la quota e il pagamento di chi online non c\'è non si possono assegnare');

        $charge = Charge::where('team_id', $this->own->id)->firstOrFail();
        $this->assertSame('STAGIONE 26/27', $charge->title);
        $this->assertSame('2026-11-20', $charge->due_on->toDateString());

        $this->assertSame(1250, (int) PlayerCharge::where('player_id', $rossi->id)->value('amount_cents'));
        $this->assertSame(1000, (int) PlayerCharge::where('player_id', $neri->id)->value('amount_cents'));
        $this->assertSame(1750, (int) Payment::sum('amount_cents'));
        $this->assertSame('saldo', Payment::where('amount_cents', 1250)->value('note'));
        $this->assertSame('satispay', Payment::where('amount_cents', 500)->value('method'));
    }

    public function test_running_it_again_creates_no_duplicates(): void
    {
        $this->online('Mario', 'Rossi');

        $file = $this->localDatabase(function ($legacy) {
            $this->localPlayer($legacy, 11, 'Mario', 'Rossi', ['phone' => '333']);
            $legacy->table('charges')->insert(['id' => 1, 'team_id' => 3, 'title' => 'STAGIONE 26/27', 'kind' => 'quota', 'amount_cents' => 1250, 'due_on' => '2026-11-20', 'season' => '26/27']);
            $legacy->table('player_charges')->insert(['id' => 1, 'charge_id' => 1, 'player_id' => 11, 'amount_cents' => 1250]);
            $legacy->table('payments')->insert([
                ['id' => 1, 'player_charge_id' => 1, 'amount_cents' => 500, 'method' => 'contanti', 'paid_at' => '2026-10-01', 'note' => null],
                ['id' => 2, 'player_charge_id' => 1, 'amount_cents' => 500, 'method' => 'bonifico', 'paid_at' => '2026-10-01', 'note' => null],
            ]);
        });

        $first = $this->upload($file)->assertOk()->json('data');
        $second = $this->upload($file)->assertOk()->json('data');

        $this->assertSame(2, $first['payments_created']);
        $this->assertSame(0, $second['payments_created']);
        $this->assertSame(2, $second['payments_already_there']);
        $this->assertSame(0, $second['charges_created']);
        $this->assertSame(0, $second['player_charges_created']);
        $this->assertSame(0, $second['players_filled']);
        $this->assertSame(1, Charge::count());
        $this->assertSame(1, PlayerCharge::count());
        $this->assertSame(2, Payment::count());
    }

    public function test_an_existing_charge_with_the_same_title_amount_and_date_is_reused(): void
    {
        $rossi = $this->online('Mario', 'Rossi');
        $charge = Charge::create(['team_id' => $this->own->id, 'title' => 'STAGIONE 26/27', 'kind' => 'quota', 'amount_cents' => 1250, 'due_on' => '2026-11-20']);

        $file = $this->localDatabase(function ($legacy) {
            $this->localPlayer($legacy, 11, 'Mario', 'Rossi');
            $legacy->table('charges')->insert(['id' => 1, 'team_id' => 3, 'title' => 'STAGIONE 26/27', 'kind' => 'quota', 'amount_cents' => 1250, 'due_on' => '2026-11-20 00:00:00']);
            $legacy->table('player_charges')->insert(['id' => 1, 'charge_id' => 1, 'player_id' => 11, 'amount_cents' => 1250]);
        });

        $stats = $this->upload($file)->assertOk()->json('data');

        $this->assertSame(0, $stats['charges_created']);
        $this->assertSame(1, Charge::count());
        $this->assertSame($charge->id, (int) PlayerCharge::where('player_id', $rossi->id)->value('charge_id'));
    }

    public function test_a_full_backup_file_works_too(): void
    {
        $this->online('Mario', 'Rossi');

        $backup = [
            'format' => 'amir-backup', 'version' => 1, 'migrations' => null,
            'tables' => [
                'players' => ['columns' => ['id', 'first_name', 'last_name', 'is_active', 'phone'], 'rows' => [[7, 'Mario', 'Rossi', 1, '333 999']]],
                'charges' => ['columns' => ['id', 'team_id', 'title', 'kind', 'amount_cents', 'due_on', 'season'], 'rows' => [[1, 3, 'CENA', null, 2000, null, null]]],
                'player_charges' => ['columns' => ['id', 'charge_id', 'player_id', 'amount_cents'], 'rows' => [[1, 1, 7, 2000]]],
                'payments' => ['columns' => ['id', 'player_charge_id', 'amount_cents', 'method', 'paid_at', 'note'], 'rows' => [[1, 1, 700, null, '2026-10-02', null], [2, 1, 300, 'strano', '2026-10-02', null]]],
            ],
        ];

        $this->upload((string) gzencode(json_encode($backup, JSON_THROW_ON_ERROR)), 'backup.json.gz')->assertOk()->assertJsonPath('data.players_matched', 1);

        $this->assertSame('333 999', Player::firstOrFail()->phone);
        $this->assertSame('altro', Charge::firstOrFail()->kind, 'senza tipo vale quello di sempre');
        $this->assertNull(Charge::firstOrFail()->due_on);
        $this->assertSame(['contanti', 'altro'], Payment::orderBy('id')->pluck('method')->all(), 'senza metodo si dà per contanti, uno sconosciuto diventa altro');
    }

    public function test_a_file_that_is_not_ours_is_refused_and_nothing_changes(): void
    {
        $rossi = $this->online('Mario', 'Rossi');

        $this->upload('questo non è un backup', 'a.txt')->assertStatus(422)->assertJsonValidationErrors('file');

        $empty = $this->localDatabase(fn ($legacy) => null);
        $this->upload($empty)->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertNull($rossi->refresh()->phone);
        $this->assertSame(0, Charge::count());
    }

    public function test_a_failure_halfway_leaves_nothing_behind(): void
    {
        $rossi = $this->online('Mario', 'Rossi');

        $file = $this->localDatabase(function ($legacy) {
            $this->localPlayer($legacy, 11, 'Mario', 'Rossi', ['phone' => '333']);
            $legacy->table('charges')->insert(['id' => 1, 'team_id' => 3, 'title' => 'STAGIONE 26/27', 'kind' => 'quota', 'amount_cents' => 1250, 'due_on' => '2026-11-20']);
            $legacy->table('player_charges')->insert(['id' => 1, 'charge_id' => 1, 'player_id' => 11, 'amount_cents' => 1250]);
            $legacy->table('payments')->insert(['id' => 1, 'player_charge_id' => 1, 'amount_cents' => 500, 'method' => 'contanti', 'paid_at' => '2026-10-01', 'note' => null]);
        });

        // il database si rompe a metà (sull'ultima tabella): niente deve restare scritto
        Payment::creating(fn () => throw new \RuntimeException('database rotto'));

        $this->upload($file)->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertNull($rossi->refresh()->phone);
        $this->assertSame(0, Charge::count());
        $this->assertSame(0, PlayerCharge::count());
    }

    public function test_only_logged_in_staff_can_use_it(): void
    {
        $this->online('Mario', 'Rossi');

        $this->upload('x', 'database.sqlite', loggedIn: false)->assertUnauthorized();
    }
}
