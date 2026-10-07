<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Crea un amministratore, o cambia la password di uno esistente.
 * La password si digita qui (non compare a schermo) e finisce nel database solo come hash:
 * non sta in .env, non sta nel codice, non sta nella cronologia del terminale.
 */
class AmirAdmin extends Command
{
    private const MIN_PASSWORD = 10;

    protected $signature = 'amir:admin
        {email : l\'email, che è anche il nome utente per accedere}
        {--name= : nome mostrato nell\'area staff (solo alla creazione)}';

    protected $description = 'Crea un amministratore o ne cambia la password (la password si digita qui, mai in .env)';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email non valida.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (almeno '.self::MIN_PASSWORD.' caratteri)');

        if (mb_strlen($password) < self::MIN_PASSWORD) {
            $this->error('La password deve avere almeno '.self::MIN_PASSWORD.' caratteri.');

            return self::FAILURE;
        }

        if ($password !== (string) $this->secret('Ripeti la password')) {
            $this->error('Le due password non coincidono.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->password = $password; // il modello la salva con hash
            $user->save();
            $user->tokens()->delete(); // chi era collegato con la vecchia password deve rientrare
            $this->info("Password di {$email} aggiornata; le sessioni aperte sono state chiuse.");

            return self::SUCCESS;
        }

        User::create([
            'name' => (string) ($this->option('name') ?: 'Amministratore'),
            'email' => $email,
            'password' => $password,
        ]);
        $this->info("Amministratore {$email} creato.");

        return self::SUCCESS;
    }
}
