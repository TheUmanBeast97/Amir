<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\Xfive\PlayerProfileSyncer;
use Illuminate\Console\Command;

class XfivePlayers extends Command
{
    protected $signature = 'xfive:players
        {--profiles-only : solo informazioni e foto (abbinamento ai profili XFive)}
        {--stats-only : solo statistiche per torneo}
        {--refresh : rifà anche i giocatori già abbinati}';

    protected $description = 'Ricava da XFive informazioni (nazionalità, foto, carriera) e statistiche dei nostri giocatori';

    public function handle(PlayerProfileSyncer $syncer): int
    {
        $own = Team::own();
        if (! $own) {
            $this->error('Squadra non configurata: esegui prima "php artisan db:seed".');

            return self::FAILURE;
        }

        if (! $this->option('stats-only')) {
            $this->info('Profili giocatori…');
            $r = $syncer->syncProfiles($own, (bool) $this->option('refresh'));
            $this->line("  abbinati: {$r['matched']} | già abbinati: {$r['skipped']} | foto: {$r['photos']}");
            foreach ($r['not_found'] as $name) {
                $this->warn("  non trovato su XFive: {$name}");
            }
            foreach ($r['ambiguous'] as $name) {
                $this->warn("  più profili possibili (da abbinare a mano): {$name}");
            }
        }

        if (! $this->option('profiles-only')) {
            $this->info('Statistiche per torneo (circa un minuto)…');
            $r = $syncer->syncStats($own);
            $this->line("  tornei: {$r['competitions']} | valori scritti: {$r['rows']} | righe di ex giocatori ignorate: {$r['unmatched_rows']}");
        }

        return self::SUCCESS;
    }
}
