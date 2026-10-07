<?php

namespace App\Services\Xfive;

use Illuminate\Support\Facades\Storage;

/**
 * Scarica stemmi e foto dal CDN delle immagini di XFive e li salva sul disco
 * locale: il frontend li legge dalla nostra API (stessa origine, con CORS),
 * così non dipende da XFive e le grafiche si possono esportare in PNG.
 */
final class ImageDownloader
{
    /** taglie da provare, dalla migliore: sul CDN esistono 's' (200 px) e 'b' (500 px). */
    private const SIZES = [
        'badge' => ['b', 's'],
        'player' => ['s', 'q'],
    ];

    public function __construct(private readonly XfiveClient $client) {}

    /**
     * Scarica l'immagine nella taglia migliore disponibile e la salva.
     * Restituisce il percorso relativo salvato, oppure null se l'immagine è
     * assente, è un segnaposto ("ph_...") o il download fallisce.
     *
     * @param  'badge'|'player'  $kind
     */
    public function store(string $url, string $kind, int $id): ?string
    {
        if ($url === '' || str_contains(basename($url), 'ph_')) {
            return null;
        }

        foreach (self::SIZES[$kind] as $size) {
            $candidate = preg_replace('#/'.($kind === 'badge' ? 'badge' : 'player').'/\w+/#', '/'.($kind === 'badge' ? 'badge' : 'player')."/{$size}/", $url, 1);
            $image = $candidate ? $this->client->download($candidate) : null;

            if ($image === null) {
                continue;
            }

            $ext = $image['type'] === 'image/jpeg' ? 'jpg' : 'png';
            $path = "media/{$kind}s/{$id}.{$ext}";

            // una sola copia per id: se l'estensione cambia si toglie la vecchia
            foreach (['png', 'jpg'] as $other) {
                if ($other !== $ext) {
                    Storage::disk('local')->delete("media/{$kind}s/{$id}.{$other}");
                }
            }
            Storage::disk('local')->put($path, $image['body']);

            return $path;
        }

        return null;
    }
}
