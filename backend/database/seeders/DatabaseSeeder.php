<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Crea la nostra squadra. L'amministratore NON si crea qui: `php artisan amir:admin <email>`
     * chiede la password a terminale, così non sta mai né in .env né nel codice.
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

        $this->command?->info('Squadra pronta.');

        if (! User::query()->exists()) {
            $this->command?->warn('Nessun amministratore: creane uno con `php artisan amir:admin tua@email.it` (la password si digita lì, non va in .env).');
        }
    }
}
