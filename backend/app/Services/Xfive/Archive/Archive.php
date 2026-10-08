<?php

namespace App\Services\Xfive\Archive;

use RuntimeException;

/**
 * L'archivio su disco dello scarico di XFive, fuori dal repository (di serie Desktop\AMIR\xfive-archive):
 *
 *   raw/        le pagine così come arrivano (HTML o JSON), per poterle rileggere senza richiederle
 *   json/       i dati estratti, per tipo (stagioni, tornei, squadre, giocatori, partite...)
 *   md/         pagine leggibili
 *   img/        stemmi e foto
 *   manifest.json  cosa è già stato fatto, per riprendere da dove ci si era fermati
 */
final class Archive
{
    /** @var array<string, mixed> */
    private array $manifest;

    public function __construct(private readonly string $root)
    {
        foreach (['raw', 'json', 'md', 'img'] as $dir) {
            if (! is_dir("{$root}/{$dir}") && ! mkdir("{$root}/{$dir}", 0775, true) && ! is_dir("{$root}/{$dir}")) {
                throw new RuntimeException("Non riesco a creare la cartella {$root}/{$dir}.");
            }
        }
        $file = "{$root}/manifest.json";
        $this->manifest = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $this->manifest += ['created_at' => date('c'), 'done' => []];
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Un nome di file sicuro ricavato da un indirizzo o da una chiave. */
    public static function slug(string $key): string
    {
        $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $key) ?? '', '-'));

        return $s === '' ? 'index' : substr($s, 0, 150);
    }

    public function putRaw(string $key, string $body, string $ext = 'html'): string
    {
        $path = 'raw/'.self::slug($key).".{$ext}";
        $this->write($path, $body);

        return $path;
    }

    public function hasRaw(string $key, string $ext = 'html'): bool
    {
        return is_file("{$this->root}/raw/".self::slug($key).".{$ext}");
    }

    public function getRaw(string $key, string $ext = 'html'): ?string
    {
        $file = "{$this->root}/raw/".self::slug($key).".{$ext}";

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /** @param  array<mixed>  $data */
    public function putJson(string $path, array $data): void
    {
        $this->write("json/{$path}.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array<mixed>|null */
    public function getJson(string $path): ?array
    {
        $file = "{$this->root}/json/{$path}.json";

        return is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    }

    public function putMarkdown(string $path, string $text): void
    {
        $this->write("md/{$path}.md", $text);
    }

    public function putImage(string $path, string $body): void
    {
        $this->write("img/{$path}", $body);
    }

    public function hasImage(string $path): bool
    {
        return is_file("{$this->root}/img/{$path}");
    }

    /** Segna una tappa come fatta (con qualche numero), così la prossima volta si salta. */
    public function markDone(string $step, array $info = []): void
    {
        $this->manifest['done'][$step] = ['at' => date('c')] + $info;
        $this->saveManifest();
    }

    public function isDone(string $step): bool
    {
        return isset($this->manifest['done'][$step]);
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->manifest;
    }

    private function write(string $relative, string $body): void
    {
        $file = "{$this->root}/{$relative}";
        $dir = dirname($file);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Non riesco a creare la cartella {$dir}.");
        }
        if (file_put_contents($file, $body) === false) {
            throw new RuntimeException("Non riesco a scrivere {$file}.");
        }
    }

    private function saveManifest(): void
    {
        $this->manifest['updated_at'] = date('c');
        $this->write('manifest.json', json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
