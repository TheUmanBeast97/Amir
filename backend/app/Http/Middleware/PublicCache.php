<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le risposte pubbliche (classifica, rosa, calendario, partite...) sono uguali per tutti e cambiano di rado: la rete di Vercel
 * le tiene a Francoforte e le serve in pochi millisecondi, senza svegliare il server (che sta in America) e il database.
 *
 *   max-age=30                  il browser le riusa per mezzo minuto
 *   s-maxage=60                 la rete di Vercel le riusa per un minuto
 *   stale-while-revalidate=3600 per un'ora dopo, serve subito la vecchia e intanto prepara la nuova: nessuno aspetta
 *
 * Quello che lo staff cambia compare quindi in meno di due minuti. Non si toccano le risposte che hanno già una regola
 * (le immagini, più lunga), gli errori e tutto ciò che non è una lettura.
 */
final class PublicCache
{
    private const RULE = 'public, max-age=30, s-maxage=60, stale-while-revalidate=3600';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethodCacheable() && $response->isSuccessful() && ! str_contains((string) $response->headers->get('Cache-Control'), 'max-age')) {
            $response->headers->set('Cache-Control', self::RULE);
        }

        return $response;
    }
}
