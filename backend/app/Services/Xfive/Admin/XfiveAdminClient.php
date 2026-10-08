<?php

namespace App\Services\Xfive\Admin;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Accesso all'area amministrazione di XFive con il tuo account, in sola lettura.
 *
 * Le credenziali vengono dalle variabili protette del server (XFIVE_ADMIN_EMAIL, XFIVE_ADMIN_PASSWORD) e vanno solo a XFive,
 * in HTTPS. Non si salva nulla: a ogni lettura si fa un accesso nuovo e il cookie di sessione resta in memoria finché la
 * richiesta dura, quindi non esiste una sessione che possa scadere o essere rubata dal database. Dopo un accesso rifiutato
 * non si riprova per un po' (una password sbagliata ripetuta potrebbe far bloccare l'account).
 */
final class XfiveAdminClient
{
    private const BLOCK_KEY = 'amir:xfive-admin-blocked-until';

    private ?CookieJar $jar = null;

    private float $lastRequestAt = 0.0;

    public function __construct(private readonly AdminRosterParser $parser) {}

    public function configured(): bool
    {
        return filled(config('amir.xfive_admin.email')) && filled(config('amir.xfive_admin.password'));
    }

    /** Accesa solo se qualcuno l'ha voluto (XFIVE_ADMIN_ENABLED) e ci sono le credenziali. */
    public function enabled(): bool
    {
        return $this->configured() && (bool) config('amir.xfive_admin.enabled');
    }

    /** Fino a quando non si riprova, dopo un accesso rifiutato. */
    public function blockedUntil(): ?int
    {
        $until = (int) Cache::get(self::BLOCK_KEY, 0);

        return $until > time() ? $until : null;
    }

    /**
     * L'elenco dei giocatori della rosa (il JSON di team.php), dopo un accesso nuovo.
     * Come fa il browser: prima si apre la pagina «Rosa» (e si controlla che l'accesso valga), poi si chiede l'elenco che la pagina
     * riempie da sola in una seconda richiesta.
     *
     * @throws XfiveAdminException
     */
    public function rosterData(): string
    {
        $this->login();

        $club = (int) config('amir.own.club_id');
        $page = $this->get('/manage_tournament.php', ['tmid' => $club, 'sk' => 'team']);

        if (! $this->parser->isLoggedIn($page)) {
            throw new XfiveAdminException('unexpected_page', 'XFive non ha restituito la pagina della rosa: la sessione non risulta valida.');
        }

        $json = $this->post('/system/include/ajax/manager/manage_tournament/team.php', $this->tableParams($club), "/manage_tournament.php?tmid={$club}&sk=team");

        if (! str_starts_with(ltrim($json), '{')) {
            throw new XfiveAdminException('unexpected_page', "XFive non ha restituito l'elenco della rosa nel formato atteso.");
        }

        return $json;
    }

    /**
     * Gli stessi parametri che la tabella della pagina manda a XFive (tabella con paginazione lato server): tutto l'elenco, senza filtri.
     *
     * @return array<string, mixed>
     */
    private function tableParams(int $club): array
    {
        $columns = [];
        foreach (range(0, 5) as $i) {
            $columns[] = ['data' => $i, 'name' => '', 'searchable' => 'true', 'orderable' => 'false', 'search' => ['value' => '', 'regex' => 'false']];
        }

        return [
            'draw' => 1,
            'columns' => $columns,
            'start' => 0,
            'length' => -1,
            'search' => ['value' => '', 'regex' => 'false'],
            'op' => 1,
            'tmid' => $club,
        ];
    }

    /**
     * Prova l'accesso senza leggere nulla.
     *
     * @return array{ok: bool, state: string, message: string}
     */
    public function check(): array
    {
        try {
            $this->login();
        } catch (XfiveAdminException $e) {
            return ['ok' => false, 'state' => $e->state, 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'state' => 'ok', 'message' => 'Accesso a XFive riuscito.'];
    }

    /** @throws XfiveAdminException */
    private function login(): void
    {
        if (! $this->configured()) {
            throw new XfiveAdminException('not_configured', 'Mancano XFIVE_ADMIN_EMAIL e XFIVE_ADMIN_PASSWORD nelle variabili del server.');
        }

        if (($until = $this->blockedUntil()) !== null) {
            $minutes = max(1, (int) ceil(($until - time()) / 60));

            throw new XfiveAdminException('blocked', "Accesso sospeso per sicurezza dopo un rifiuto di XFive: nuovo tentativo fra circa {$minutes} minuti.");
        }

        $base = rtrim((string) config('amir.xfive.base_url'), '/');
        if (! str_starts_with($base, 'https://')) {
            throw new XfiveAdminException('unexpected_page', 'Le credenziali di XFive si inviano solo in HTTPS: controlla XFIVE_BASE_URL.');
        }

        $this->jar = new CookieJar;

        try {
            $this->pause();
            $this->http()->asForm()->post('/login.php', [
                'url' => '',
                'btn' => 't-btn',
                'mail' => (string) config('amir.xfive_admin.email'),
                'password' => (string) config('amir.xfive_admin.password'),
            ]);

            $home = $this->get('/manage_tournament.php', ['lid' => 1, 'sk' => 'home']);
        } catch (ConnectionException) {
            throw new XfiveAdminException('unreachable', 'XFive non risponde. Riprova tra poco.');
        }

        if (! $this->parser->isLoggedIn($home)) {
            $minutes = max(1, (int) config('amir.xfive_admin.cooldown_minutes'));
            Cache::put(self::BLOCK_KEY, time() + $minutes * 60, now()->addMinutes($minutes));

            throw new XfiveAdminException('login_failed', "XFive ha rifiutato l'accesso: controlla email e password. Nuovi tentativi sospesi per {$minutes} minuti.");
        }

        Cache::forget(self::BLOCK_KEY);
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws XfiveAdminException
     */
    private function get(string $path, array $query = []): string
    {
        $this->pause();

        try {
            $response = $this->http()->get($path, $query);
        } catch (ConnectionException) {
            throw new XfiveAdminException('unreachable', 'XFive non risponde. Riprova tra poco.');
        }

        $this->assertSuccessful($response);

        return $response->body();
    }

    /**
     * Una richiesta come quelle che la pagina fa da sola (AJAX): stessi parametri, segnalata come tale e con la pagina di partenza.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws XfiveAdminException
     */
    private function post(string $path, array $data, string $referer): string
    {
        $this->pause();

        try {
            $response = $this->http()->asForm()->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'Referer' => rtrim((string) config('amir.xfive.base_url'), '/').$referer,
            ])->post($path, $data);
        } catch (ConnectionException) {
            throw new XfiveAdminException('unreachable', 'XFive non risponde. Riprova tra poco.');
        }

        $this->assertSuccessful($response);

        return $response->body();
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->serverError()) {
            throw new XfiveAdminException('unreachable', 'XFive ha avuto un problema. Riprova tra poco.');
        }
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('amir.xfive.base_url'), '/'))
            ->withUserAgent((string) config('amir.xfive.user_agent'))
            ->timeout((int) config('amir.xfive.timeout'))
            ->withOptions([
                'cookies' => $this->jar ?? new CookieJar,
                'allow_redirects' => ['max' => 5, 'strict' => false, 'protocols' => ['https']],
            ]);
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
