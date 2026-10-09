<?php

namespace App\Console\Commands;

use App\Services\Zone\ZoneImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** Importa in locale i pezzi di xfive:zone-export (per provare con i dati veri; online si caricano dalla Staff Area). */
class XfiveZoneImport extends Command
{
    protected $signature = 'xfive:zone-import {dir : la cartella con i pezzi zone-NNN-sezione.json.gz}';

    protected $description = 'Importa nel database tutti i pezzi dell\'export della Mixed Zone di una cartella, in ordine di nome';

    public function handle(ZoneImporter $importer): int
    {
        $dir = rtrim((string) $this->argument('dir'), '/\\');
        $files = glob($dir.DIRECTORY_SEPARATOR.'zone-*.json.gz') ?: [];
        sort($files, SORT_STRING);
        if (! $files) {
            $this->error("In {$dir} non ci sono pezzi zone-*.json.gz.");

            return self::FAILURE;
        }

        $started = microtime(true);
        $totals = [];
        foreach ($files as $i => $file) {
            $t = microtime(true);
            try {
                $r = $importer->chunk((string) file_get_contents($file));
            } catch (InvalidArgumentException $e) {
                $this->error(basename($file).': '.$e->getMessage());

                return self::FAILURE;
            }
            foreach ($r['counts'] as $k => $v) {
                $totals[$k] = ($totals[$k] ?? 0) + $v;
            }
            $this->line(sprintf('  %d/%d %-36s %-12s %5d elementi  %5.1f s  %s', $i + 1, count($files), basename($file), $r['section'], $r['items'], microtime(true) - $t,
                implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($r['counts']), $r['counts']))));
        }

        $this->info(sprintf('%d pezzi in %.1f s: %s.', count($files), microtime(true) - $started,
            implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($totals), $totals))));

        return self::SUCCESS;
    }
}
