<?php

namespace App\Console\Commands;

use App\Services\Xfive\XfiveSyncService;
use Illuminate\Console\Command;

class XfiveSync extends Command
{
    protected $signature = 'xfive:sync {scope=current : current (stagione in corso) oppure history (stagioni passate)}';

    protected $description = 'Aggiorna calendario, risultati e storico dalle pagine pubbliche di XFive';

    public function handle(XfiveSyncService $sync): int
    {
        $scope = (string) $this->argument('scope');

        if (! in_array($scope, ['current', 'history'], true)) {
            $this->error('Scope non valido: usa "current" oppure "history".');

            return self::INVALID;
        }

        $this->info("Sincronizzazione XFive ({$scope})…");
        $run = $sync->run($scope);

        $this->line('Esito: '.$run->status.' — '.json_encode($run->stats));
        if ($run->error) {
            $this->warn($run->error);
        }

        return $run->status === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
