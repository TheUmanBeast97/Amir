<?php

namespace App\Console\Commands;

use App\Services\Zone\ZoneExporter;
use Illuminate\Console\Command;
use RuntimeException;

/** Prepara i pezzi gzip della Mixed Zone dall'archivio locale di XFive, da caricare poi online (Staff Area, Sincronizzazione). */
class XfiveZoneExport extends Command
{
    protected $signature = 'xfive:zone-export
        {--dir= : la cartella json/ dell\'archivio (di serie <archivio>/json, con l\'archivio da XFIVE_ARCHIVE_DIR oppure Desktop\\AMIR\\xfive-archive)}
        {--out= : dove scrivere i pezzi (di serie <archivio>/export)}';

    protected $description = 'Esporta l\'archivio locale di XFive (solo calcio) in pezzi gzip sotto i 3,5 MB, pronti per il caricamento online';

    public function handle(ZoneExporter $exporter): int
    {
        $archive = XfiveArchive::defaultDir();
        $jsonDir = (string) ($this->option('dir') ?: $archive.DIRECTORY_SEPARATOR.'json');
        $outDir = (string) ($this->option('out') ?: $archive.DIRECTORY_SEPARATOR.'export');

        if (! is_file($jsonDir.DIRECTORY_SEPARATOR.'tournaments.json')) {
            $this->error("In {$jsonDir} non c'è tournaments.json: non è la cartella json/ dell'archivio.");

            return self::FAILURE;
        }

        $this->line("Archivio: {$jsonDir}");
        $this->line("Pezzi in: {$outDir}");
        $started = microtime(true);

        try {
            $result = $exporter->export($jsonDir, $outDir);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $total = 0;
        foreach ($result['files'] as $file) {
            $size = (int) filesize($file);
            $total += $size;
            $this->line(sprintf('  %-40s %8.1f KB', basename($file), $size / 1024));
        }
        $counts = $result['counts'];
        $this->info(sprintf(
            '%d pezzi, %.1f MB in tutto, %.1f s. Tornei %d, club %d, squadre %d, profili %d, calendari %d, tabelle %d, referti %d.',
            $counts['files'], $total / 1_048_576, microtime(true) - $started,
            $counts['tournaments'], $counts['clubs'], $counts['teams'], $counts['players'], $counts['calendar'], $counts['tables'], $counts['reports'],
        ));

        return self::SUCCESS;
    }
}
