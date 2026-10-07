<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Copia di sicurezza e ripristino: file JSON compresso, oppure il vecchio database SQLite del gestionale locale. */
class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    private function loginAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['email' => 'capo@example.com']));
    }

    private function player(string $last, array $extra = []): Player
    {
        $team = Team::firstOrCreate(['xfive_club_id' => 159], ['name' => 'AMIR COSTRUZIONI', 'short_name' => 'AMIR', 'is_own' => true, 'format' => 8]);

        return Player::create(['team_id' => $team->id, 'first_name' => 'Luca', 'last_name' => $last] + $extra);
    }

    /** Il backup come lista di tabelle, preso dalla risposta di download. */
    private function downloadBackup(): array
    {
        $response = $this->get('/api/v1/backup')->assertOk();
        $this->assertStringContainsString('.json.gz', (string) $response->headers->get('Content-Disposition'));

        return json_decode((string) gzdecode($response->getContent()), true, 16, JSON_THROW_ON_ERROR);
    }

    private function restore(array|string $backup, string $confirm = 'RIPRISTINA', bool $gzip = true)
    {
        $content = is_array($backup) ? json_encode($backup, JSON_THROW_ON_ERROR) : $backup;

        return $this->post('/api/v1/backup/restore', [
            'file' => UploadedFile::fake()->createWithContent('backup.json.gz', is_array($backup) && $gzip ? gzencode($content) : $content),
            'confirm' => $confirm,
        ], ['Accept' => 'application/json']);
    }

    public function test_the_backup_holds_all_the_data_in_one_compressed_file(): void
    {
        $this->loginAsAdmin();
        $this->player('Rossi');

        $backup = $this->downloadBackup();

        $this->assertSame('amir-backup', $backup['format']);
        $players = $backup['tables']['players'];
        $this->assertSame('Rossi', $players['rows'][0][array_search('last_name', $players['columns'], true)]);
        $users = $backup['tables']['users'];
        $this->assertSame('capo@example.com', $users['rows'][0][array_search('email', $users['columns'], true)]);
    }

    public function test_restoring_brings_back_the_old_data_and_logs_everybody_out(): void
    {
        $this->loginAsAdmin();
        User::first()->createToken('web');
        $this->player('Rossi', ['is_active' => false]);
        $backup = $this->downloadBackup();

        $this->player('Bianchi'); // dopo la copia
        $this->assertSame(2, Player::count());

        $this->restore($backup)->assertOk()->assertJsonPath('data.restored', true);

        $this->assertSame(['Rossi'], Player::pluck('last_name')->all());
        $this->assertFalse((bool) Player::first()->is_active, 'i valori sì/no restano come erano');
        $this->assertTrue(User::where('email', 'capo@example.com')->exists(), 'chi era nella copia può ancora accedere');
        $this->assertSame(0, DB::table('personal_access_tokens')->count(), 'dopo il ripristino si accede di nuovo');
    }

    public function test_a_plain_json_file_works_too_and_new_rows_keep_getting_fresh_ids(): void
    {
        $this->loginAsAdmin();
        $this->player('Rossi');
        $backup = $this->downloadBackup();

        // un id alto nel file: i nuovi giocatori devono ripartire da lì (in Postgres serve riallineare il contatore)
        $columns = $backup['tables']['players']['columns'];
        $backup['tables']['players']['rows'][0][array_search('id', $columns, true)] = 50;

        $this->restore($backup, gzip: false)->assertOk();

        $this->assertSame(50, Player::first()->id);
        $this->assertGreaterThan(50, $this->player('Verdi')->id);
    }

    public function test_a_file_that_is_not_our_backup_is_refused_and_nothing_changes(): void
    {
        $this->loginAsAdmin();
        $this->player('Rossi');
        $good = $this->downloadBackup();

        $this->restore('questo è solo testo')->assertStatus(422)->assertJsonValidationErrors('file');
        $this->restore(['format' => 'altro', 'tables' => []])->assertStatus(422)->assertJsonValidationErrors('file');

        // mancano le tabelle indispensabili
        $partial = $good;
        unset($partial['tables']['matches']);
        $this->restore($partial)->assertStatus(422)->assertJsonValidationErrors('file');

        // senza la parola di conferma
        $this->restore($good, 'si')->assertStatus(422)->assertJsonValidationErrors('confirm');

        $this->assertSame(['Rossi'], Player::pluck('last_name')->all());
    }

    public function test_a_crafted_file_with_tables_we_do_not_know_is_refused(): void
    {
        $this->loginAsAdmin();
        $this->player('Rossi');

        $backup = $this->downloadBackup();
        $backup['tables']['extra'] = ['columns' => ['id'], 'rows' => [[1]]];

        $this->restore($backup)->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame(['Rossi'], Player::pluck('last_name')->all(), 'il database in uso non è stato toccato');
    }

    public function test_names_in_the_file_never_become_sql(): void
    {
        $this->loginAsAdmin();
        $this->player('Rossi');

        $backup = $this->downloadBackup();
        // nomi di colonna e di tabella costruiti ad arte: non esistono nel database, quindi non vengono usati
        $backup['tables']['players']['columns'][] = 'id"; DROP TABLE users; --';
        foreach ($backup['tables']['players']['rows'] as $i => $row) {
            $backup['tables']['players']['rows'][$i][] = 'x';
        }

        $this->restore($backup)->assertOk();

        $this->assertSame(['Rossi'], Player::pluck('last_name')->all());
        $this->assertTrue(User::where('email', 'capo@example.com')->exists());
    }

    public function test_a_backup_without_any_user_is_refused_so_nobody_is_locked_out(): void
    {
        $this->loginAsAdmin();
        $backup = $this->downloadBackup();
        $backup['tables']['users']['rows'] = [];

        $this->restore($backup)->assertStatus(422);

        $this->assertTrue(User::where('email', 'capo@example.com')->exists());
    }

    public function test_a_file_with_clashing_data_is_refused_and_the_current_data_survive(): void
    {
        $this->loginAsAdmin();
        $this->player('Rossi');
        $backup = $this->downloadBackup();

        $this->player('Bianchi');

        // due utenti con la stessa email: il database rifiuta, e tutto torna com'era
        $columns = $backup['tables']['users']['columns'];
        $second = $backup['tables']['users']['rows'][0];
        $second[array_search('id', $columns, true)] = 99;
        $backup['tables']['users']['rows'][] = $second;

        $this->restore($backup)->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertEqualsCanonicalizing(['Rossi', 'Bianchi'], Player::pluck('last_name')->all());
    }

    public function test_a_backup_never_carries_sessions_tokens_cache_or_queued_jobs(): void
    {
        $this->loginAsAdmin();
        User::first()->createToken('web');
        DB::table('cache')->insert(['key' => 'k', 'value' => 'O:8:"stdClass":0:{}', 'expiration' => 2000000000]);
        $this->assertSame(1, DB::table('personal_access_tokens')->count());

        $backup = $this->downloadBackup();

        $this->assertArrayHasKey('users', $backup['tables']);
        foreach (['personal_access_tokens', 'cache', 'sessions', 'jobs', 'migrations', 'media_files'] as $table) {
            $this->assertArrayNotHasKey($table, $backup['tables'], "{$table} non deve stare nel backup");
        }
        // e i dati in uso non hanno perso nulla
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_restoring_never_imports_tokens_or_serialized_leftovers_from_a_crafted_file(): void
    {
        $this->loginAsAdmin();
        $backup = $this->downloadBackup();

        // un file costruito ad arte: un accesso "revocato" e un oggetto serializzato nella cache (anche con altre maiuscole)
        $backup['tables']['personal_access_tokens'] = ['columns' => ['tokenable_type', 'tokenable_id', 'name', 'token'], 'rows' => [['App\\Models\\User', 1, 'vecchio', 'abc']]];
        $backup['tables']['CACHE'] = ['columns' => ['key', 'value', 'expiration'], 'rows' => [['evil', 'O:8:"stdClass":0:{}', 2000000000]]];
        $backup['tables']['jobs'] = ['columns' => ['queue', 'payload', 'attempts', 'available_at', 'created_at'], 'rows' => [['default', 'O:8:"stdClass":0:{}', 0, 0, 0]]];

        $this->restore($backup)->assertOk();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(0, DB::table('cache')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertTrue(User::where('email', 'capo@example.com')->exists());
    }

    public function test_the_old_sqlite_database_of_the_local_app_can_be_restored(): void
    {
        $dir = sys_get_temp_dir().'/amir-legacy-'.bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            // il gestionale locale: stesso schema, file SQLite vero, con i suoi dati
            config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => $dir.'/database.sqlite', 'prefix' => '', 'foreign_key_constraints' => true]]);
            touch($dir.'/database.sqlite');
            Artisan::call('migrate', ['--database' => 'legacy', '--force' => true]);

            $legacy = DB::connection('legacy');
            $legacy->table('users')->insert(['id' => 7, 'name' => 'Capo', 'email' => 'vecchio@example.com', 'password' => 'x']);
            $legacy->table('teams')->insert(['id' => 3, 'name' => 'AMIR COSTRUZIONI', 'short_name' => 'AMIR', 'xfive_club_id' => 159, 'is_own' => 1, 'format' => 8]);
            $legacy->table('players')->insert(['id' => 11, 'team_id' => 3, 'first_name' => 'Mario', 'last_name' => 'Antico', 'is_active' => 1, 'access_token' => str_repeat('a', 64)]);
            $legacy->table('cache')->insert(['key' => 'k', 'value' => 'x', 'expiration' => 2000000000]);
            DB::purge('legacy');

            $this->loginAsAdmin();
            $this->player('Attuale');

            $this->post('/api/v1/backup/restore', [
                'file' => UploadedFile::fake()->createWithContent('database.sqlite', (string) file_get_contents($dir.'/database.sqlite')),
                'confirm' => 'RIPRISTINA',
            ], ['Accept' => 'application/json'])->assertOk();

            $this->assertSame(['Antico'], Player::pluck('last_name')->all());
            $this->assertSame(11, Player::first()->id);
            $this->assertTrue((bool) Player::first()->is_active);
            $this->assertTrue(User::where('email', 'vecchio@example.com')->exists());
            $this->assertFalse(User::where('email', 'capo@example.com')->exists(), 'gli utenti sono quelli del file');
            $this->assertSame(0, DB::table('cache')->count());
        } finally {
            DB::purge('legacy');
            File::deleteDirectory($dir);
        }
    }

    public function test_an_sqlite_file_of_something_else_is_refused(): void
    {
        $dir = sys_get_temp_dir().'/amir-legacy-'.bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $pdo = new \PDO('sqlite:'.$dir.'/altra.sqlite');
            $pdo->exec('CREATE TABLE cose (id INTEGER)');
            unset($pdo);

            $this->loginAsAdmin();
            $this->player('Rossi');

            $this->post('/api/v1/backup/restore', [
                'file' => UploadedFile::fake()->createWithContent('altra.sqlite', (string) file_get_contents($dir.'/altra.sqlite')),
                'confirm' => 'RIPRISTINA',
            ], ['Accept' => 'application/json'])->assertStatus(422);

            $this->assertSame(['Rossi'], Player::pluck('last_name')->all());
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_only_logged_in_staff_can_download_or_restore(): void
    {
        $this->getJson('/api/v1/backup')->assertUnauthorized();
        $this->postJson('/api/v1/backup/restore', [])->assertUnauthorized();
    }
}
