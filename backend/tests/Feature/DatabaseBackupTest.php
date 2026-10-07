<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use PDO;
use Tests\TestCase;

/** Backup e ripristino: lavorano su un vero file SQLite temporaneo (mai su quello dell'app). */
class DatabaseBackupTest extends TestCase
{
    private string $dir;

    private string $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/amir-backup-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->db = $this->dir.'/database.sqlite';
        touch($this->db);

        config(['database.connections.sqlite.database' => $this->db]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function loginAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(['email' => 'capo@example.com']));
    }

    private function player(string $last): Player
    {
        $team = Team::firstOrCreate(['xfive_club_id' => 159], ['name' => 'AMIR COSTRUZIONI', 'short_name' => 'AMIR', 'is_own' => true, 'format' => 8]);

        return Player::create(['team_id' => $team->id, 'first_name' => 'Luca', 'last_name' => $last]);
    }

    /** Il database come file, preso dalla risposta di download. */
    private function downloadBackup(): string
    {
        $response = $this->get('/api/v1/backup')->assertOk();
        $copy = $this->dir.'/copia-'.bin2hex(random_bytes(3)).'.sqlite';
        copy($response->baseResponse->getFile()->getPathname(), $copy);

        return $copy;
    }

    public function test_the_backup_is_a_real_copy_of_all_the_data(): void
    {
        $this->loginAsAdmin();
        $this->player('Rossi');

        $copy = $this->downloadBackup();

        $pdo = new PDO('sqlite:'.$copy);
        $this->assertSame('ok', $pdo->query('PRAGMA quick_check')->fetchColumn());
        $this->assertSame('Rossi', $pdo->query('SELECT last_name FROM players')->fetchColumn());
        $this->assertSame('capo@example.com', $pdo->query('SELECT email FROM users')->fetchColumn());
    }

    public function test_restoring_brings_back_the_old_data_and_keeps_the_previous_version_aside(): void
    {
        $this->loginAsAdmin();
        $this->player('Rossi');
        $copy = $this->downloadBackup();

        $this->player('Bianchi'); // dopo la copia
        $this->assertSame(2, Player::count());

        $this->post('/api/v1/backup/restore', [
            'file' => UploadedFile::fake()->createWithContent('backup.sqlite', (string) file_get_contents($copy)),
            'confirm' => 'RIPRISTINA',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.restored', true);

        $this->assertSame(['Rossi'], Player::pluck('last_name')->all());
        $this->assertTrue(User::where('email', 'capo@example.com')->exists(), 'chi era nella copia può ancora accedere');

        // la versione di prima del ripristino resta accanto, per tornare indietro
        $before = new PDO('sqlite:'.$this->db.'.prima-del-ripristino');
        $this->assertSame(2, (int) $before->query('SELECT COUNT(*) FROM players')->fetchColumn());
    }

    public function test_a_file_that_is_not_our_database_is_refused_and_nothing_changes(): void
    {
        $this->loginAsAdmin();
        $this->player('Rossi');

        $try = fn (UploadedFile $file, string $confirm = 'RIPRISTINA') => $this->post('/api/v1/backup/restore', ['file' => $file, 'confirm' => $confirm], ['Accept' => 'application/json']);

        $try(UploadedFile::fake()->createWithContent('a.sqlite', 'questo è solo testo'))
            ->assertStatus(422)->assertJsonValidationErrors('file');

        // un SQLite valido ma di un'altra app
        $other = $this->dir.'/altra.sqlite';
        $pdo = new PDO('sqlite:'.$other);
        $pdo->exec('CREATE TABLE cose (id INTEGER)');
        $try(UploadedFile::fake()->createWithContent('b.sqlite', (string) file_get_contents($other)))
            ->assertStatus(422)->assertJsonValidationErrors('file');

        // senza la parola di conferma
        $try(UploadedFile::fake()->createWithContent('c.sqlite', (string) file_get_contents($this->downloadBackup())), 'si')
            ->assertStatus(422)->assertJsonValidationErrors('confirm');

        $this->assertSame(['Rossi'], Player::pluck('last_name')->all());
        $this->assertFileDoesNotExist($this->db.'.prima-del-ripristino');
    }

    public function test_a_backup_without_any_user_is_refused_so_nobody_is_locked_out(): void
    {
        $this->loginAsAdmin();
        $copy = $this->downloadBackup();
        (new PDO('sqlite:'.$copy))->exec('DELETE FROM users');

        $this->post('/api/v1/backup/restore', [
            'file' => UploadedFile::fake()->createWithContent('vuoto.sqlite', (string) file_get_contents($copy)),
            'confirm' => 'RIPRISTINA',
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertTrue(User::where('email', 'capo@example.com')->exists());
    }

    public function test_only_logged_in_staff_can_download_or_restore(): void
    {
        $this->getJson('/api/v1/backup')->assertUnauthorized();
        $this->postJson('/api/v1/backup/restore', [])->assertUnauthorized();
    }
}
