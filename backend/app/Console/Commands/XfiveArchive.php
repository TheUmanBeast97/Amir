<?php

namespace App\Console\Commands;

use App\Services\Xfive\Archive\Archive;
use App\Services\Xfive\Archive\Limiter;
use App\Services\Xfive\Archive\SiteMapper;
use App\Services\Xfive\Archive\XfiveArchiver;
use App\Services\Xfive\MatchPageParser;
use App\Services\Xfive\PlayerInfoParser;
use App\Services\Xfive\PrintableCalendarParser;
use App\Services\Xfive\StatsTableParser;
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
        {stage=all : la tappa: all, tournaments, details, teams, clubs, players, matches, images, markdown (oppure map e probe per studiare il sito)}
        {--dir= : cartella dell\'archivio (di serie XFIVE_ARCHIVE_DIR oppure Desktop\\AMIR\\xfive-archive)}
        {--gap=1.0 : secondi fra una richiesta e l\'altra}
        {--hours= : finestra oraria consentita, es. 23-6 (fuori si aspetta)}
        {--limit=0 : massimo di richieste in questa esecuzione (0 = senza limite); il resto alla prossima}
        {--seasons= : per «tournaments»: id delle stagioni da elencare, es. 6,7,8 (di serie quelle in config)}
        {--scan= : per «tournaments»: intervallo di id di torneo da provare uno a uno, es. 1-200 (per le stagioni vecchie)}
        {--only= : per «details»: solo alcune parti, es. calendar,standings (anche: stats, teams, docs); con --refresh rilegge dal sito}
        {--page=* : per «map»: altre pagine da leggere oltre alla home, es. /it/tournaments/}
        {--post=* : per «probe»: una chiamata interna da provare, come «league.php op=21&tid=187»}
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
            $stage = (string) $this->argument('stage');

            return match (true) {
                $stage === 'map' => $this->map($client, $mapper),
                $stage === 'probe' => $this->probe($client, $mapper),
                $stage === 'all' || in_array($stage, XfiveArchiver::STAGES, true) => $this->archiveStages($client, $stage),
                default => $this->fail("Tappa sconosciuta: {$stage}."),
            };
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $this->line("Richieste fatte: {$this->limiter->requests}, rallentamenti: {$this->limiter->slowdowns}.");
        }
    }

    /** Le tappe dello scarico vero, una o tutte in fila. */
    private function archiveStages(XfiveClient $client, string $stage): int
    {
        $archiver = new XfiveArchiver(
            $client, $this->limiter, $this->archive,
            app(PrintableCalendarParser::class), app(StatsTableParser::class), app(MatchPageParser::class), app(PlayerInfoParser::class),
            refresh: (bool) $this->option('refresh'),
        );
        $archiver->reportTo(fn (string $line) => $this->line($line));
        if ((int) $this->option('limit') > 0) {
            $archiver->withBudget((int) $this->option('limit'));
        }

        $seasons = $this->option('seasons')
            ? array_map('intval', explode(',', (string) $this->option('seasons')))
            : array_keys((array) config('amir.xfive.seasons'));
        $scan = null;
        if ($this->option('scan') && preg_match('/^(\d+)-(\d+)$/', (string) $this->option('scan'), $m)) {
            $scan = [(int) $m[1], (int) $m[2]];
        }

        $stages = $stage === 'all' ? XfiveArchiver::STAGES : [$stage];
        foreach ($stages as $s) {
            $this->info("Tappa «{$s}»…");
            $only = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only')))));
            $stats = match ($s) {
                'tournaments' => $archiver->tournaments($seasons, $scan),
                'details' => $archiver->details($only),
                default => $archiver->{$s}(),
            };
            $this->line('  '.implode(', ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($stats), $stats)));
            if ($archiver->requestsLeft() <= 0) {
                $this->warn('Budget di richieste finito: la prossima esecuzione riprende da qui.');
                break;
            }
        }

        return self::SUCCESS;
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

    /**
     * Prova le chiamate interne del sito (quelle che le pagine fanno da sole per riempirsi): salva la risposta e,
     * se contiene HTML, ne stampa la mappa. Serve a capire come sono fatti elenchi e tabelle prima di leggerli davvero.
     */
    private function probe(XfiveClient $client, SiteMapper $mapper): int
    {
        $host = (string) parse_url((string) config('amir.xfive.base_url'), PHP_URL_HOST);

        foreach ((array) $this->option('post') as $spec) {
            [$script, $query] = array_pad(explode(' ', trim((string) $spec), 2), 2, '');
            parse_str($query, $data);
            $data += ['lid' => config('amir.xfive.league_id')];
            $key = 'ajax-'.$script.'-'.$query;

            $json = $this->option('refresh') ? null : $this->archive->getRaw($key, 'json');
            if ($json === null) {
                $this->line("Chiamo {$script} con {$query}…");
                $answer = $this->fetch(fn () => json_encode($client->post('/system/include/ajax/public/'.$script, $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                if ($answer === null) {
                    $this->warn('  non esiste (404).');

                    continue;
                }
                $json = $answer;
                $this->archive->putRaw($key, $json, 'json');
            }

            $obj = json_decode($json, true) ?: [];
            $this->info("{$script} {$query}: chiavi ".implode(', ', array_keys($obj)).' ('.strlen($json).' byte)');
            if (! empty($obj['errors'])) {
                $this->warn('  errori: '.json_encode($obj['errors'], JSON_UNESCAPED_UNICODE));
            }
            $html = is_string($obj['html'] ?? null) ? $obj['html'] : null;
            if ($html !== null && $html !== '') {
                $m = $mapper->map($html, preg_replace('/^www\./', '', $host) ?? $host);
                foreach (array_slice($m['links'], 0, 25, true) as $shape => $g) {
                    $this->line(sprintf('    %4d  %-40s %s', $g['count'], $shape, implode('  ', $g['examples'])));
                }
                foreach ($m['selects'] as $s) {
                    $this->line('  Tendina '.($s['name'] ?? $s['id'] ?? '?').': '.count($s['options']).' voci: '.implode(' | ', array_map(fn ($o) => "{$o['value']}={$o['label']}", array_slice($s['options'], 0, 12))));
                }
                $this->line('  Testo: '.mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? ''), 0, 400));
            }
        }

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
        return self::defaultDir((string) $this->option('dir'));
    }

    /** La cartella dell'archivio: quella passata, altrimenti XFIVE_ARCHIVE_DIR, altrimenti Desktop\AMIR\xfive-archive (anche per xfive:zone-export). */
    public static function defaultDir(string $option = ''): string
    {
        $dir = $option !== '' ? $option : (string) (env('XFIVE_ARCHIVE_DIR') ?: '');
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
