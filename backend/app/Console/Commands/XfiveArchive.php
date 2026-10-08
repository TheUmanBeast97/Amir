<?php

namespace App\Console\Commands;

use App\Services\Xfive\Archive\Archive;
use App\Services\Xfive\Archive\Limiter;
use App\Services\Xfive\Archive\SiteMapper;
use App\Services\Xfive\XfiveClient;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Scarica in locale, con calma, i dati pubblici di XFive (autorizzazione data a voce dal responsabile di XFive al
 * nostro staff). Tutto finisce in una cartella fuori dal repository; niente va sul sito pubblico da qui.
 *
 * Tappe:
 *   map   legge le pagine di navigazione e ne stampa la mappa (link, tendine, chiamate interne): serve a progettare il resto
 */
class XfiveArchive extends Command
{
    protected $signature = 'xfive:archive
        {stage=map : la tappa: map}
        {--dir= : cartella dell\'archivio (di serie XFIVE_ARCHIVE_DIR oppure Desktop\\AMIR\\xfive-archive)}
        {--gap=1.0 : secondi fra una richiesta e l\'altra}
        {--hours= : finestra oraria consentita, es. 23-6 (fuori si aspetta)}
        {--page=* : per «map»: altre pagine da leggere oltre alla home, es. /it/tournaments/}
        {--refresh : rilegge anche le pagine già salvate}';

    protected $description = 'Scarica in locale i dati pubblici di XFive, piano e riprendendo da dove si era fermato';

    private Archive $archive;

    private Limiter $limiter;

    public function handle(XfiveClient $client, SiteMapper $mapper): int
    {
        $this->archive = new Archive($this->dir());
        $this->limiter = new Limiter(gap: max(0.5, (float) $this->option('gap')), window: $this->window());

        $this->line("Archivio: {$this->archive->root()}  (pausa {$this->limiter->gap()} s, ".($this->window() ? 'ore '.$this->option('hours') : 'nessuna finestra oraria').')');

        try {
            return match ($this->argument('stage')) {
                'map' => $this->map($client, $mapper),
                default => $this->fail("Tappa sconosciuta: {$this->argument('stage')}."),
            };
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $this->line("Richieste fatte: {$this->limiter->requests}, rallentamenti: {$this->limiter->slowdowns}.");
        }
    }

    private function map(XfiveClient $client, SiteMapper $mapper): int
    {
        $pages = array_values(array_unique(array_merge(['/'], (array) $this->option('page'))));
        $host = (string) parse_url((string) config('amir.xfive.base_url'), PHP_URL_HOST);
        $report = [];

        foreach ($pages as $page) {
            $key = 'page'.$page;
            $html = $this->option('refresh') ? null : $this->archive->getRaw($key);
            if ($html === null) {
                $this->line("Leggo {$page}…");
                $html = $this->fetch(fn () => $client->get($page));
                if ($html === null) {
                    $this->warn("  {$page}: non esiste (404), salto.");

                    continue;
                }
                $this->archive->putRaw($key, $html);
            } else {
                $this->line("{$page}: già in archivio, uso quella (--refresh per rileggere).");
            }

            $m = $mapper->map($html, preg_replace('/^www\./', '', $host) ?? $host);
            $report[$page] = $m;

            $this->info("{$page}  «{$m['title']}»");
            $this->line('  Link per forma (quanti, esempi):');
            foreach (array_slice($m['links'], 0, 40, true) as $shape => $g) {
                $this->line(sprintf('    %4d  %-40s %s', $g['count'], $shape, implode('  ', $g['examples'])));
            }
            foreach ($m['selects'] as $s) {
                $this->line('  Tendina '.($s['name'] ?? $s['id'] ?? '?').': '.count($s['options']).' voci: '.implode(' | ', array_map(fn ($o) => "{$o['value']}={$o['label']}", array_slice($s['options'], 0, 12))));
            }
            if ($m['ajax']) {
                $this->line('  Chiamate interne: '.implode(', ', $m['ajax']));
            }
            foreach ($m['forms'] as $f) {
                $this->line('  Form '.($f['method'] ?? 'get').' '.($f['action'] ?? '(stessa pagina)').': '.implode(', ', $f['fields']));
            }
        }

        $this->archive->putJson('map', $report);
        $this->archive->markDone('map', ['pages' => count($report)]);
        $this->line('Mappa salvata in json/map.json; le pagine in raw/.');

        return self::SUCCESS;
    }

    /** Una richiesta attraverso il freno: null se la pagina non esiste (404). */
    private function fetch(\Closure $request): ?string
    {
        try {
            return $this->limiter->run($request, fn (Throwable $e) => str_contains($e->getMessage(), 'HTTP 404'));
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }
    }

    private function dir(): string
    {
        $dir = (string) ($this->option('dir') ?: env('XFIVE_ARCHIVE_DIR') ?: '');
        if ($dir === '') {
            $home = getenv('USERPROFILE') ?: getenv('HOME') ?: sys_get_temp_dir();
            $dir = $home.DIRECTORY_SEPARATOR.'Desktop'.DIRECTORY_SEPARATOR.'AMIR'.DIRECTORY_SEPARATOR.'xfive-archive';
        }

        return rtrim($dir, '/\\');
    }

    /** @return array{0:int,1:int}|null */
    private function window(): ?array
    {
        $h = (string) $this->option('hours');
        if ($h === '' || ! preg_match('/^(\d{1,2})-(\d{1,2})$/', $h, $m)) {
            return null;
        }

        return [(int) $m[1] % 24, (int) $m[2] % 24];
    }
}
