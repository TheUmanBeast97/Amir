<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Crea la nostra squadra e l'utente amministratore.
     * Credenziali: ADMIN_EMAIL / ADMIN_PASSWORD in .env (la password non è nel codice).
     */
    public function run(): void
    {
        $own = config('amir.own');

        Team::updateOrCreate(
            ['xfive_club_id' => $own['club_id']],
            [
                'xfive_public_id' => $own['public_team_id'],
                'name' => $own['name'],
                'short_name' => $own['short_name'],
                'is_own' => true,
                'format' => $own['format'],
                'kit1_color' => $own['kit1_color'],
                'kit2_color' => $own['kit2_color'],
            ],
        );

        $email = (string) env('ADMIN_EMAIL', 'admin@amir.local');
        $password = env('ADMIN_PASSWORD');

        if ($password === null || $password === '') {
            $this->command?->error('ADMIN_PASSWORD non impostata in .env: utente admin NON creato.');

            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            ['name' => 'Amministratore', 'password' => $password],
        );

        $this->command?->info("Squadra e utente admin pronti ({$email}).");
    }
}
