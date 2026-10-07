<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Dove stanno gli stemmi e le foto scaricati da XFive: nel database (tabella media_files), così sopravvivono
 * dove il disco non resta. I file salvati su disco dalle versioni precedenti si leggono ancora (e si portano
 * nel database con `php artisan amir:media-import`).
 */
final class MediaStore
{
    public function put(string $path, string $body, string $mime): void
    {
        DB::table('media_files')->upsert(
            [['path' => $path, 'mime' => $mime, 'bytes' => strlen($body), 'data' => base64_encode($body), 'created_at' => now(), 'updated_at' => now()]],
            ['path'],
            ['mime', 'bytes', 'data', 'updated_at'],
        );
    }

    /** @return array{body: string, mime: string}|null */
    public function get(string $path): ?array
    {
        $row = DB::table('media_files')->where('path', $path)->first(['mime', 'data']);
        if ($row) {
            $body = base64_decode((string) $row->data, true);

            return $body === false ? null : ['body' => $body, 'mime' => (string) $row->mime];
        }

        $disk = Storage::disk('local');

        return $disk->exists($path) ? ['body' => (string) $disk->get($path), 'mime' => self::mimeOf($path)] : null;
    }

    public function exists(string $path): bool
    {
        return DB::table('media_files')->where('path', $path)->exists() || Storage::disk('local')->exists($path);
    }

    /**
     * Quali di questi percorsi hanno davvero l'immagine (un backup ripristinato ha i percorsi ma non le immagini).
     *
     * @param  array<int, string>  $paths
     * @return array<string, true> percorso => true
     */
    public function existingPaths(array $paths): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique($paths)), 500) as $chunk) {
            foreach (DB::table('media_files')->whereIn('path', $chunk)->pluck('path') as $path) {
                $found[(string) $path] = true;
            }
        }

        $disk = Storage::disk('local');
        foreach ($paths as $path) {
            if (! isset($found[$path]) && $disk->exists($path)) {
                $found[$path] = true;
            }
        }

        return $found;
    }

    public function forget(string $path): void
    {
        DB::table('media_files')->where('path', $path)->delete();
        Storage::disk('local')->delete($path);
    }

    /** Porta nel database i file salvati su disco dalle versioni precedenti. Restituisce quanti ne ha copiati. */
    public function importFromDisk(): int
    {
        $disk = Storage::disk('local');
        $copied = 0;

        foreach ($disk->allFiles('media') as $path) {
            $this->put($path, (string) $disk->get($path), self::mimeOf($path));
            $copied++;
        }

        return $copied;
    }

    public static function mimeOf(string $path): string
    {
        return str_ends_with(strtolower($path), '.jpg') || str_ends_with(strtolower($path), '.jpeg') ? 'image/jpeg' : 'image/png';
    }
}
