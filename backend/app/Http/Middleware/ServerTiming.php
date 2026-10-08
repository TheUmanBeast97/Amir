<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scrive in ogni risposta quanto tempo ha richiesto il server e quanto di quello è andato nel database
 * (intestazione standard Server-Timing: si vede negli strumenti del browser, scheda Rete, Timing).
 * Serve a capire dove si perde il tempo quando il sito va piano: non contiene nessun dato, solo durate e numero di interrogazioni.
 */
final class ServerTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $queries = 0;
        $dbMs = 0.0;

        DB::listen(function ($query) use (&$queries, &$dbMs) {
            $queries++;
            $dbMs += $query->time;
        });

        $response = $next($request);
        $response->headers->set('Server-Timing', sprintf(
            'app;dur=%.0f, db;dur=%.0f;desc="%d query"',
            (microtime(true) - $start) * 1000,
            $dbMs,
            $queries,
        ));

        return $response;
    }
}
