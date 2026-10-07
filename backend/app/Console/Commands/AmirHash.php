<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Calcola l'impronta (hash) di una password, senza salvarla da nessuna parte.
 * Serve per il primo avvio su un server: l'impronta si può mettere nelle variabili d'ambiente, la password no.
 */
class AmirHash extends Command
{
    protected $signature = 'amir:hash';

    protected $description = 'Calcola l\'impronta di una password per il primo avvio su un server (la password si digita qui e non viene salvata)';

    public function handle(): int
    {
        $password = (string) $this->secret('Password (almeno 10 caratteri)');

        if (mb_strlen($password) < 10) {
            $this->error('La password deve avere almeno 10 caratteri.');

            return self::FAILURE;
        }

        if ($password !== (string) $this->secret('Ripeti la password')) {
            $this->error('Le due password non coincidono.');

            return self::FAILURE;
        }

        $this->line(Hash::make($password));

        return self::SUCCESS;
    }
}
