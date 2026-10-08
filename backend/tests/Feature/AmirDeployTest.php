<?php

namespace Tests\Feature;

use Tests\TestCase;

/** La pubblicazione su Vercel: trova l'indirizzo del database, e se manca lo dice senza mostrare nulla di riservato. */
class AmirDeployTest extends TestCase
{
    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed}> valori originali delle variabili toccate */
    private array $touched = [];

    protected function tearDown(): void
    {
        foreach ($this->touched as $key => [$process, $env, $server]) {
            $process === false ? putenv($key) : putenv("{$key}={$process}");
            $env === null ? $this->forget($_ENV, $key) : $_ENV[$key] = $env;
            $server === null ? $this->forget($_SERVER, $key) : $_SERVER[$key] = $server;
        }

        parent::tearDown();
    }

    private function forget(array &$bag, string $key): void
    {
        unset($bag[$key]);
    }

    private function setEnv(string $key, ?string $value): void
    {
        $this->touched[$key] ??= [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];

        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /** Nessuna variabile del database, come in un progetto Vercel senza Neon. */
    private function withoutDatabaseVariables(): void
    {
        foreach (['DB_URL', 'DB_HOST', 'DATABASE_URL', 'DATABASE_URL_UNPOOLED', 'POSTGRES_URL', 'POSTGRES_URL_NON_POOLING'] as $key) {
            $this->setEnv($key, null);
        }
    }

    private function databaseConfig(): array
    {
        return require config_path('database.php');
    }

    public function test_production_without_a_database_stops_and_lists_variable_names_never_values(): void
    {
        $this->withoutDatabaseVariables();
        $this->setEnv('VERCEL_ENV', 'production');
        $this->setEnv('STORAGE_PGHOST', 'host-segreto.example');
        config(['database.default' => 'pgsql', 'database.connections.pgsql.url' => null]);

        $this->artisan('amir:deploy')
            ->expectsOutputToContain('Manca il database')
            ->expectsOutputToContain('STORAGE_PGHOST')
            ->doesntExpectOutputToContain('host-segreto.example')
            ->assertFailed();
    }

    public function test_a_preview_without_a_database_is_skipped_not_failed(): void
    {
        $this->withoutDatabaseVariables();
        $this->setEnv('VERCEL_ENV', 'preview');
        config(['database.default' => 'pgsql', 'database.connections.pgsql.url' => null]);

        $this->artisan('amir:deploy')->expectsOutputToContain('salto la preparazione')->assertSuccessful();
    }

    public function test_the_direct_address_wins_over_the_pooled_one(): void
    {
        $this->withoutDatabaseVariables();
        $this->setEnv('DATABASE_URL', 'postgres://u:p@pooler.example/db');
        $this->setEnv('DATABASE_URL_UNPOOLED', 'postgres://u:p@diretto.example/db');

        $this->assertSame('postgres://u:p@diretto.example/db', $this->databaseConfig()['connections']['pgsql']['url']);

        $this->setEnv('DB_URL', 'postgres://u:p@scelto-a-mano.example/db');
        $this->assertSame('postgres://u:p@scelto-a-mano.example/db', $this->databaseConfig()['connections']['pgsql']['url'], 'DB_URL ha sempre la precedenza');
    }

    public function test_an_address_smuggled_in_through_request_headers_is_never_used(): void
    {
        $this->withoutDatabaseVariables();
        // in un runtime di tipo CGI le intestazioni di una richiesta diventano variabili HTTP_*: chi le manda non sceglie il database
        $this->setEnv('HTTP_X_STORAGE_DATABASE_URL', 'postgres://attaccante:x@attaccante.example/db');
        $this->setEnv('HTTP_DATABASE_URL', 'postgres://attaccante:x@attaccante.example/db');
        $this->setEnv('STORAGE_DATABASE_URL', 'postgres://u:p@prefisso.example/db'); // un prefisso non si indovina: va copiato in DB_URL
        $this->setEnv('REDIS_DB_URL', 'redis://non-e-postgres.example');

        $this->assertNull($this->databaseConfig()['connections']['pgsql']['url']);
    }

    public function test_without_any_address_the_config_has_none(): void
    {
        $this->withoutDatabaseVariables();

        $this->assertNull($this->databaseConfig()['connections']['pgsql']['url']);
    }
}
