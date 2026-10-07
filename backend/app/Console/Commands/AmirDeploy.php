<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Prepara il database di un server: tabelle, squadra e primo amministratore. Su Vercel lo lancia la pubblicazione stessa
 * (script "vercel" di composer.json), perché lì non esiste un momento di avvio in cui farlo a mano. Si può rilanciare a piacere.
 */
class AmirDeploy extends Command
{
    protected $signature = 'amir:deploy';

    protected $description = 'Prepara il database online: tabelle, squadra e primo amministratore (lo lancia Vercel a ogni pubblicazione)';

    public function handle(): int
    {
        if (! $this->databaseIsConfigured()) {
            if (env('VERCEL_ENV') === 'production') {
                $this->error('Manca il database: nel progetto Vercel apri Storage, crea un database Postgres (Neon) e collegalo al progetto, poi pubblica di nuovo.');

                return self::FAILURE;
            }

            $this->warn('Database non configurato: salto la preparazione (succede nelle anteprime).');

            return self::SUCCESS;
        }

        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--force' => true]);

        $email = (string) config('amir.admin.email');
        $hash = (string) config('amir.admin.hash');

        if ($email !== '' && $hash !== '') {
            // se l'utente c'è già non cambia nulla: le due variabili si possono togliere dopo il primo avvio
            $status = $this->call('amir:admin', ['email' => $email, '--hash' => $hash, '--if-missing' => true]);

            if ($status !== self::SUCCESS) {
                $this->warn('Amministratore non creato: controlla AMIR_ADMIN_EMAIL e AMIR_ADMIN_HASH (si ottiene con `php artisan amir:hash`).');
            }
        } elseif (! User::query()->exists()) {
            $this->warn('Nessun amministratore: imposta AMIR_ADMIN_EMAIL e AMIR_ADMIN_HASH (`php artisan amir:hash` calcola l\'impronta della password).');
        }

        return self::SUCCESS;
    }

    private function databaseIsConfigured(): bool
    {
        if (config('database.default') !== 'pgsql') {
            return true;
        }

        return filled(config('database.connections.pgsql.url')) || filled(env('DB_HOST'));
    }
}
