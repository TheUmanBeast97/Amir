<?php

namespace App\Services\Zone;

use RuntimeException;

/**
 * Dall'archivio locale di XFive (la cartella json/) ai pezzi gzip da caricare online: un JSON {"section", "items"} per
 * file, numerati nell'ordine in cui vanno importati (tornei, club, squadre, profili, calendari, tabelle, referti) e
 * spezzati quando il JSON supera il limite prima della compressione (Vercel accetta richieste sotto i 4,5 MB).
 * Solo calcio: i tornei di altri sport e tutto ciò che dipende da loro restano nell'archivio.
 */
final class ZoneExporter
{
    public const SECTIONS = ['tournaments', 'clubs', 'teams', 'players', 'calendar', 'tables', 'reports'];

    /** @var array<int, string> */
    private array $files = [];

    /** @var array<string, int> */
    private array $counts = [];

    private int $number = 0;

    private string $outDir = '';

    private int $maxBytes = 3_500_000;

    /**
     * @param  string  $jsonDir  la cartella con tournaments.json, tournaments/, teams/, clubs/, players/, matches/
     * @param  string  $outDir  dove scrivere i pezzi (i vecchi zone-*.json.gz si cancellano)
     * @return array{files: array<int,string>, counts: array<string,int>}
     */
    public function export(string $jsonDir, string $outDir, int $maxBytes = 3_500_000): array
    {
        $jsonDir = rtrim($jsonDir, '/\\');
        $this->outDir = rtrim($outDir, '/\\');
        $this->maxBytes = max(100, $maxBytes);
        $this->files = [];
        $this->counts = array_fill_keys(self::SECTIONS, 0);
        $this->number = 0;

        if (! is_dir($this->outDir) && ! mkdir($this->outDir, 0775, true) && ! is_dir($this->outDir)) {
            throw new RuntimeException("Non riesco a creare la cartella {$this->outDir}.");
        }
        foreach (glob("{$this->outDir}/zone-*.json.gz") ?: [] as $old) {
            @unlink($old);
        }

        // 1. tornei di calcio: intestazioni (tournament.json, altrimenti la voce dell'indice)
        $headers = [];
        foreach ($this->readJson("{$jsonDir}/tournaments.json") ?? [] as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            if ($id <= 0 || ($entry['exists'] ?? true) === false) {
                continue;
            }
            $header = $this->readJson("{$jsonDir}/tournaments/{$id}/tournament.json") ?? $entry;
            if (($header['exists'] ?? true) === false || ! ZoneImporter::isFootball((string) ($header['sport'] ?? ''))) {
                continue;
            }
            $headers[$id] = $header;
        }
        ksort($headers);
        $football = array_fill_keys(array_keys($headers), true);
        $this->section('tournaments', (function () use ($headers) {
            foreach ($headers as $h) {
                yield $h;
            }
        })());

        // 2. club citati nei calendari o nelle rose dei tornei di calcio
        $clubIds = [];
        foreach ($headers as $id => $h) {
            foreach ($this->readJson("{$jsonDir}/tournaments/{$id}/calendar.json") ?? [] as $row) {
                foreach (['home', 'away'] as $side) {
                    if (! empty($row[$side]['club_id'])) {
                        $clubIds[(int) $row[$side]['club_id']] = true;
                    }
                }
            }
        }
        $teamFiles = [];
        foreach ($this->jsonFiles("{$jsonDir}/teams") as $file) {
            $team = $this->readJson($file);
            if ($team && isset($football[(int) ($team['tournament_id'] ?? 0)])) {
                $teamFiles[] = $file;
                if (! empty($team['club_id'])) {
                    $clubIds[(int) $team['club_id']] = true;
                }
            }
        }
        ksort($clubIds);
        $this->section('clubs', (function () use ($jsonDir, $clubIds) {
            foreach (array_keys($clubIds) as $cid) {
                $club = $this->readJson("{$jsonDir}/clubs/{$cid}.json");
                if ($club) {
                    yield $club;
                }
            }
        })());

        // 3. squadre e rose
        $this->section('teams', (function () use ($teamFiles) {
            foreach ($teamFiles as $file) {
                $team = $this->readJson($file);
                if ($team) {
                    yield $team;
                }
            }
        })());

        // 4. profili con almeno un torneo di calcio
        $this->section('players', (function () use ($jsonDir, $football) {
            foreach ($this->jsonFiles("{$jsonDir}/players") as $file) {
                $p = $this->readJson($file);
                if ($p && $this->playsFootball($p, $football)) {
                    yield $p;
                }
            }
        })());

        // 5. calendari
        $this->section('calendar', (function () use ($jsonDir, $headers) {
            foreach ($headers as $id => $h) {
                $rows = $this->readJson("{$jsonDir}/tournaments/{$id}/calendar.json");
                if ($rows !== null) {
                    yield ['tournament_id' => $id, 'season' => (string) ($h['season'] ?? ''), 'rows' => array_values($rows)];
                }
            }
        })());

        // 6. classifiche, statistiche e documenti
        $this->section('tables', (function () use ($jsonDir, $headers) {
            foreach (array_keys($headers) as $id) {
                $standings = $this->readJson("{$jsonDir}/tournaments/{$id}/standings.json");
                $stats = $this->readJson("{$jsonDir}/tournaments/{$id}/player-stats.json");
                $docs = $this->readJson("{$jsonDir}/tournaments/{$id}/docs.json");
                if ($standings === null && $stats === null && $docs === null) {
                    continue;
                }
                yield ['tournament_id' => $id, 'standings' => array_values($standings ?? []), 'player_stats' => $stats ?? [], 'docs' => array_values($docs ?? [])];
            }
        })());

        // 7. referti delle partite dei tornei di calcio
        $this->section('reports', (function () use ($jsonDir, $football) {
            foreach ($this->jsonFiles("{$jsonDir}/matches") as $file) {
                $m = $this->readJson($file);
                if ($m && isset($football[(int) ($m['tournament_id'] ?? 0)])) {
                    yield $m;
                }
            }
        })());

        return ['files' => $this->files, 'counts' => $this->counts + ['files' => count($this->files)]];
    }

    /** Scrive gli elementi di una sezione in uno o più pezzi, spezzando quando il JSON supera il limite. */
    private function section(string $section, iterable $items): void
    {
        $buffer = [];
        $size = 0;
        foreach ($items as $item) {
            $json = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($buffer && $size + strlen($json) + 1 > $this->maxBytes) {
                $this->flush($section, $buffer);
                $buffer = [];
                $size = 0;
            }
            $buffer[] = $json;
            $size += strlen($json) + 1;
            $this->counts[$section]++;
        }
        if ($buffer) {
            $this->flush($section, $buffer);
        }
    }

    /** @param  array<int, string>  $encodedItems */
    private function flush(string $section, array $encodedItems): void
    {
        $this->number++;
        $name = sprintf('zone-%03d-%s.json.gz', $this->number, $section);
        $body = '{"section":'.json_encode($section).',"items":['.implode(',', $encodedItems).']}';
        $gz = gzencode($body, 6);
        if ($gz === false || file_put_contents("{$this->outDir}/{$name}", $gz) === false) {
            throw new RuntimeException("Non riesco a scrivere {$this->outDir}/{$name}.");
        }
        $this->files[] = "{$this->outDir}/{$name}";
    }

    /** @param  array<int, true>  $football */
    private function playsFootball(array $profile, array $football): bool
    {
        foreach ((array) ($profile['clubs'] ?? []) as $club) {
            foreach ((array) ($club['tournaments'] ?? []) as $t) {
                if (isset($football[(int) ($t['id'] ?? 0)])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<int, string> i file .json di una cartella, in ordine di nome */
    private function jsonFiles(string $dir): array
    {
        $files = is_dir($dir) ? (glob("{$dir}/*.json") ?: []) : [];
        sort($files, SORT_NATURAL);

        return $files;
    }

    /** @return array<mixed>|null */
    private function readJson(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }
}
