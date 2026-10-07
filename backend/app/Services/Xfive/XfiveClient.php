<?php

namespace App\Services\Xfive;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client HTTP verso le pagine PUBBLICHE di XFive (nessun login).
 * Una richiesta alla volta, con una pausa fra l'una e l'altra, per non
 * appesantire il sito di un'associazione.
 */
class XfiveClient
{
    private float $lastRequestAt = 0.0;

    /** Calendario completo di un torneo (pagina "Anteprima di stampa"). */
    public function printableCalendar(int $tournamentId): string
    {
        return $this->get('/t-printable.php', ['t' => $tournamentId, 'sk' => 'calendar']);
    }

    /** Frammento HTML con i tornei disputati da un club in una stagione. */
    public function clubSeasonTournaments(int $clubId, int $seasonId): string
    {
        $json = $this->post('/system/include/ajax/public/team.php', [
            'lid' => config('amir.xfive.league_id'),
            'tmid' => $clubId,
            'op' => 1,
            'sid' => $seasonId,
        ]);

        return (string) ($json['html'] ?? '');
    }

    /**
     * Ricerca pubblica del sito (la barra "Cerca tornei, squadre, giocatori").
     *
     * @return array<int, array<string, mixed>>
     */
    public function finder(string $term): array
    {
        $this->throttle();
        $response = $this->http()->asForm()->post('/system/include/ajax/public/finder.php', [
            'term' => $term,
            'lid' => config('amir.xfive.league_id'),
        ]);

        if ($response->failed()) {
            throw new RuntimeException("XFive ricerca fallita (HTTP {$response->status()})");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Classifica statistica di un torneo, come HTML: $type = score (marcatori),
     * top-player (miglior giocatore) o discipline (ammonizioni/espulsioni).
     */
    public function tournamentStats(int $tournamentId, string $type): string
    {
        $json = $this->post('/system/include/ajax/public/league.php', [
            'op' => 19,
            'tid' => $tournamentId,
            'type' => $type,
        ]);

        return (string) ($json['html'] ?? '');
    }

    /**
     * Scarica un'immagine dal CDN di XFive (solo i suoi host).
     *
     * @return array{body: string, type: string}|null null se non è un'immagine valida
     */
    public function download(string $url): ?array
    {
        if (! in_array(parse_url($url, PHP_URL_HOST), ['cdn.enjore.com', 'www.xfivesport.it'], true)) {
            return null;
        }

        $this->throttle();
        $response = Http::withUserAgent((string) config('amir.xfive.user_agent'))
            ->timeout((int) config('amir.xfive.timeout'))
            ->get($url);

        if (! $response->successful()) {
            return null;
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $body = $response->body();

        if (! in_array($type, ['image/png', 'image/jpeg'], true) || strlen($body) < 500) {
            return null;
        }

        return ['body' => $body, 'type' => $type];
    }

    public function get(string $path, array $query = []): string
    {
        $this->throttle();
        $response = $this->http()->get($path, $query);

        if ($response->failed()) {
            throw new RuntimeException("XFive GET {$path} fallita (HTTP {$response->status()})");
        }

        return $response->body();
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $data): array
    {
        $this->throttle();
        $response = $this->http()->asForm()->post($path, $data);

        if ($response->failed()) {
            throw new RuntimeException("XFive POST {$path} fallita (HTTP {$response->status()})");
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException("XFive POST {$path}: la risposta non è JSON");
        }

        return $json;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('amir.xfive.base_url'), '/'))
            ->withUserAgent((string) config('amir.xfive.user_agent'))
            ->timeout((int) config('amir.xfive.timeout'))
            ->retry(2, 1500, null, false);
    }

    private function throttle(): void
    {
        $gap = ((int) config('amir.xfive.throttle_ms')) / 1000;

        if ($this->lastRequestAt > 0) {
            $wait = $gap - (microtime(true) - $this->lastRequestAt);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        }

        $this->lastRequestAt = microtime(true);
    }
}
