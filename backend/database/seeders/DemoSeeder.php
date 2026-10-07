<?php

namespace Database\Seeders;

use App\Models\Player;
use App\Models\Team;
use App\Models\TeamEvent;
use App\Services\FinanceService;
use Illuminate\Database\Seeder;

/**
 * Dati INVENTATI per provare l'app (nomi di fantasia, nessun dato reale).
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(FinanceService $finance): void
    {
        $own = Team::ownOrFail();

        $people = [
            ['Marco', 'Valli', 'portiere', '1'], ['Nicolò', 'Ferrante', 'portiere', '12'],
            ['Tommaso', 'Brandi', 'difensore', '3'], ['Gianluca', 'Serra', 'difensore', '4'],
            ['Federico', 'Landi', 'difensore', '5'], ['Davide', 'Moretti', 'centrocampista', '8'],
            ['Alessio', 'Corsini', 'centrocampista', '6'], ['Matteo', 'Pellegri', 'centrocampista', '10'],
            ['Simone', 'Arduini', 'centrocampista', '14'], ['Luca', 'Bonetti', 'attaccante', '9'],
            ['Andrea', 'Fabbri', 'attaccante', '11'], ['Riccardo', 'Gallo', 'attaccante', '7'],
            ['Paolo', 'Rinaldi', 'difensore', '2'], ['Stefano', 'Mariani', 'centrocampista', '16'],
            ['Giorgio', 'Testa', 'allenatore', 'A'], ['Enrico', 'Sala', 'dirigente', 'D'],
        ];

        foreach ($people as $i => [$first, $last, $role, $shirt]) {
            $isStaff = in_array($role, ['allenatore', 'dirigente'], true);

            Player::updateOrCreate(
                ['team_id' => $own->id, 'first_name' => $first, 'last_name' => $last],
                [
                    'role' => $role,
                    'shirt_number' => $shirt,
                    'in_squad_list' => ! $isStaff && $i < 10,
                    'registration_status' => $i % 3 === 0 ? 'pending' : ($i % 3 === 1 ? 'approved' : 'none'),
                    'medical_cert_expires_on' => now()->addDays(15 + $i * 20)->toDateString(),
                ],
            );
        }

        TeamEvent::updateOrCreate(
            ['team_id' => $own->id, 'type' => 'training', 'title' => 'Allenamento (demo)'],
            ['starts_at' => now()->addDays(3)->setTime(21, 0), 'venue' => '100GRIGIO - CAMPO 4'],
        );

        if (! \App\Models\Charge::where('team_id', $own->id)->exists()) {
            $season = $finance->createCharge($own, [
                'title' => 'Quota stagione (demo)', 'kind' => 'quota_stagione',
                'amount_cents' => 12000, 'due_on' => now()->addDays(20)->toDateString(),
            ]);
            $tess = $finance->createCharge($own, [
                'title' => 'Tesseramento (demo)', 'kind' => 'tesseramento',
                'amount_cents' => 1000, 'due_on' => now()->subDays(2)->toDateString(),
            ]);

            // qualche pagamento d'esempio
            foreach ($season->playerCharges()->limit(5)->get() as $pc) {
                $finance->addPayment($pc, ['amount_cents' => 12000, 'method' => 'satispay', 'paid_at' => now()->toDateString()]);
            }
            foreach ($tess->playerCharges()->limit(8)->get() as $pc) {
                $finance->addPayment($pc, ['amount_cents' => 1000, 'method' => 'contanti', 'paid_at' => now()->toDateString()]);
            }
        }
    }
}
