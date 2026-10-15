<?php

namespace App\Services\Xfive\Admin;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * L'accesso a XFive di una persona dello staff, con le credenziali che ha digitato lei: serve per cose che si fanno con un
 * account vero (la distinta ufficiale), non per le letture automatiche (quelle usano XfiveAdminClient).
 *
 * Questa classe non conserva niente: fa il login e restituisce il cookie di sessione, poi fa richieste con il cookie che le si
 * passa. Chi la usa (XfiveSessionStore) decide dove e come tenerlo. Password e cookie non compaiono mai nei messaggi d'errore.
 */
final class XfiveAccess
{
    private float $lastRequestAt = 0.0;

    public function __construct(private readonly AdminRosterParser $parser) {}

    /**
     * Fa il login e restituisce i cookie della sessione (da cifrare prima di salvarli).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws XfiveAdminException
     */
    public function login(string $email, string $password): array
    {
        $base = rtrim((string) config('amir.xfive.base_url'), '/');
        if (! str_starts_with($base, 'https://')) {
            throw new XfiveAdminException('unexpected_page', 'Le credenziali di XFive si inviano solo in HTTPS: controlla XFIVE_BASE_URL.');
        }

        $jar = new CookieJar;

        try {
            $this->pause();
            $this->http($jar)->asForm()->post('/login.php', [
                'url' => '',
                'btn' => 't-btn',
                'mail' => $email,
                'password' => $password,
            ]);

            $this->pause();
            $home = $this->http($jar)->get('/manage_tournament.php', ['lid' => 1, 'sk' => 'home']);
        } catch (ConnectionException) {
            throw new XfiveAdminException('unreachable', 'XFive non risponde. Riprova tra poco.');
        }

        if ($home->serverError()) {
            throw new XfiveAdminException('unreachable', 'XFive ha avuto un problema. Riprova tra poco.');
        }

        if (! $this->parser->isLoggedIn($home->body())) {
            throw new XfiveAdminException('login_failed', "XFive ha rifiutato l'accesso: controlla email e password.");
        }

        return $jar->toArray();
    }

    /** Esce da XFive (se non riesce non importa: la sessione si cancella comunque da qui). */
    public function logout(array $cookies): void
    {
        try {
            $this->pause();
            $this->http($this->jar($cookies))->get('/logout.php');
        } catch (\Throwable) {
            // la sessione locale si cancella comunque
        }
    }

    /** @param  array<int, array<string, mixed>>  $cookies */
    public function jar(array $cookies): CookieJar
    {
        return new CookieJar(false, array_map(fn (array $c) => new SetCookie($c), $cookies));
    }

    /**
     * Una richiesta come quelle che la pagina fa da sola (AJAX) e la risposta in JSON.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws XfiveAdminException  session_expired se XFive risponde con la pagina di accesso
     */
    public function ajax(CookieJar $jar, string $path, array $data, string $referer): array
    {
        try {
            $this->pause();
            $response = $this->http($jar)->asForm()->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'Referer' => rtrim((string) config('amir.xfive.base_url'), '/').$referer,
            ])->post($path, $data);
        } catch (ConnectionException) {
            throw new XfiveAdminException('unreachable', 'XFive non risponde. Riprova tra poco.');
        }

        if ($response->serverError()) {
            throw new XfiveAdminException('unreachable', 'XFive ha avuto un problema. Riprova tra poco.');
        }

        $body = $this->utf8($response->body());
        $json = json_decode($body, true);

        if (! is_array($json)) {
            // se la sessione è finita XFive risponde con la pagina del sito (senza «Esci»)
            $loggedIn = $this->parser->isLoggedIn($body);

            throw new XfiveAdminException(
                $loggedIn ? 'unexpected_page' : 'session_expired',
                $loggedIn ? 'XFive ha risposto in un formato che non conosco.' : 'La sessione di XFive è scaduta: collegati di nuovo.',
            );
        }

        return $json;
    }

    private function http(CookieJar $jar): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('amir.xfive.base_url'), '/'))
            ->withUserAgent((string) config('amir.xfive.user_agent'))
            ->timeout((int) config('amir.xfive.timeout'))
            ->withOptions([
                'cookies' => $jar,
                'allow_redirects' => ['max' => 5, 'strict' => false, 'protocols' => ['https']],
            ]);
    }

    private function utf8(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
    }

    /** Stessa pausa fra le richieste di tutto il resto della sincronizzazione con XFive. */
    private function pause(): void
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
