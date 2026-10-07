<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Rete di sicurezza per il server: se al primo avvio il database non è pronto (tabelle mancanti o nuove migrazioni da applicare,
 * amministratore non ancora creato) lo prepara con `amir:deploy`. Di norma lo fa già la pubblicazione (script "vercel" di
 * composer.json): qui conta solo se quel passaggio non è partito (per esempio perché il database è stato collegato dopo).
 * Una sola volta per istanza, con un blocco perché due richieste in parallelo non migrino insieme.
 */
final class DatabaseBootstrap
{
    private const LOCK_KEY = 7731;

    private static bool $checked = false;

    public function ensureReady(): bool
    {
        if (self::$checked) {
            return false;
        }
        self::$checked = true;

        try {
            if (! $this->needsWork()) {
                return false;
            }

            $this->withLock(function () {
                // un'altra richiesta può aver finito nel frattempo
                if ($this->needsWork()) {
                    Artisan::call('amir:deploy');
                }
            });

            return true;
        } catch (Throwable $e) {
            // se il database non risponde lo dirà la richiesta stessa: qui non si deve peggiorare le cose
            Log::warning('Preparazione del database non riuscita: '.$e->getMessage());

            return false;
        }
    }

    /** Solo per i test: permette di rifare il controllo. */
    public static function reset(): void
    {
        self::$checked = false;
    }

    private function needsWork(): bool
    {
        $migrator = app('migrator');
        $repository = $migrator->getRepository();

        if (! $repository->repositoryExists()) {
            return true;
        }

        $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
        if (array_diff($files, $repository->getRan()) !== []) {
            return true;
        }

        $email = (string) config('amir.admin.email');
        $hash = (string) config('amir.admin.hash');

        return $email !== '' && $hash !== '' && Schema::hasTable('users') && ! User::query()->exists();
    }

    private function withLock(callable $work): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $work();

            return;
        }

        DB::select('select pg_advisory_lock(?)', [self::LOCK_KEY]);
        try {
            $work();
        } finally {
            DB::select('select pg_advisory_unlock(?)', [self::LOCK_KEY]);
        }
    }
}
