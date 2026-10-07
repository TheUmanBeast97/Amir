<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Services\DatabaseBootstrap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Sul server il primo avvio prepara da solo il database, se la pubblicazione non l'ha già fatto. */
class DatabaseBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DatabaseBootstrap::reset();
    }

    public function test_nothing_happens_when_the_database_is_already_ready(): void
    {
        $this->assertFalse(app(DatabaseBootstrap::class)->ensureReady());
    }

    public function test_a_database_without_tables_is_prepared_with_the_squad_and_the_first_admin(): void
    {
        $dir = sys_get_temp_dir().'/amir-boot-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $original = config('database.default');

        try {
            touch($dir.'/vuoto.sqlite');
            config([
                'database.connections.vuoto' => ['driver' => 'sqlite', 'database' => $dir.'/vuoto.sqlite', 'prefix' => '', 'foreign_key_constraints' => true],
                'database.default' => 'vuoto',
                'amir.admin.email' => 'Capo@Example.com',
                'amir.admin.hash' => Hash::make('password-di-prova-1'),
            ]);
            DB::purge('vuoto');

            $this->assertFalse(Schema::hasTable('players'));

            $this->assertTrue(app(DatabaseBootstrap::class)->ensureReady());

            $this->assertTrue(Schema::hasTable('players'));
            $this->assertTrue(Team::where('is_own', true)->exists(), 'la squadra è pronta');
            $this->assertTrue(User::where('email', 'capo@example.com')->exists(), "l'amministratore è creato dall'impronta della password");

            // una volta fatto non si ripete
            DatabaseBootstrap::reset();
            $this->assertFalse(app(DatabaseBootstrap::class)->ensureReady());
        } finally {
            // la connessione di prova va tolta prima della fine del test: la pulizia di RefreshDatabase usa quella di sempre
            config(['database.default' => $original]);
            DB::purge('vuoto');
            File::deleteDirectory($dir);
        }
    }

    public function test_a_missing_admin_is_created_when_the_variables_are_set_later(): void
    {
        config(['amir.admin.email' => 'capo@example.com', 'amir.admin.hash' => Hash::make('password-di-prova-1')]);
        $this->assertSame(0, User::count());

        $this->assertTrue(app(DatabaseBootstrap::class)->ensureReady());

        $this->assertTrue(User::where('email', 'capo@example.com')->exists());
    }

    public function test_it_only_checks_once_per_instance(): void
    {
        $boot = app(DatabaseBootstrap::class);
        $boot->ensureReady();

        config(['amir.admin.email' => 'capo@example.com', 'amir.admin.hash' => Hash::make('password-di-prova-1')]);

        $this->assertFalse($boot->ensureReady(), 'già controllato: nessuna query in più sulle richieste successive');
        $this->assertSame(0, User::count());
    }
}
