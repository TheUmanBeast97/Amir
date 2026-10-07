<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sul server l'app sta dietro il proxy HTTPS della piattaforma: senza questo gli indirizzi di foto e stemmi
        // uscirebbero con http:// e il browser li bloccherebbe nel sito https.
        // Se si conoscono gli indirizzi del proxy si possono restringere con TRUSTED_PROXIES (separati da virgola).
        $trusted = getenv('TRUSTED_PROXIES');
        $middleware->trustProxies(at: $trusted ? array_map('trim', explode(',', $trusted)) : '*');

        // Nessuna pagina di login da raggiungere: senza token si risponde 401 (JSON), mai un redirect
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // L'app è solo API: errori e validazioni sempre in JSON
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());
    })->create();
