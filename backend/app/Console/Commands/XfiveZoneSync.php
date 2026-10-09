<?php

namespace App\Console\Commands;

use App\Services\Xfive\XfiveRoutine;
use App\Services\Zone\ZoneSync;
use Illuminate\Console\Command;

/** Un pezzo di aggiornamento della Mixed Zone dalla console, come lo farebbe il cron: stessi scope, stesso budget, con l'avanzamento a video. */
class XfiveZoneSync extends Command
{
    protected $signature = 'xfive:zone-sync
        {section=zone : zone (tutte in fila) oppure zone-tournaments, zone-calendar, zone-standings, zone-stats, zone-teams, zone-reports, zone-players}
        {--budget=40 : secondi a disposizione, come sul server}';

    protected $description = 'Aggiorna dal vivo una sezione della Mixed Zone da XFive (XfiveRoutine, scope zone-*) e stampa esito e avanzamento';

    public function handle(XfiveRoutine $routine): int
    {
        $section = (string) $this->argument('section');
        if ($section !== 'zone' && ! in_array($section, ZoneSync::SECTIONS, true)) {
            $this->error('Sezione non valida: zone oppure '.implode(', ', ZoneSync::SECTIONS).'.');

            return self::INVALID;
        }

        $routine->onProgress(fn (string $s, string $message, int $done, int $total) => $this->line("  [{$s}] {$message}"));
        $started = microtime(true);
        $this->info("Mixed Zone, {$section} (budget {$this->option('budget')} s)…");
        $run = $routine->run($section, null, (float) $this->option('budget'));

        $this->line(sprintf('Esito: %s in %.1f s', $run->status, microtime(true) - $started));
        $this->line('stats: '.json_encode($run->stats, JSON_UNESCAPED_UNICODE));
        $this->line('progress: '.json_encode($run->progress, JSON_UNESCAPED_UNICODE));
        if ($run->error) {
            $this->warn($run->error);
        }

        return $run->status === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
