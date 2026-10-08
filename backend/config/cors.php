<?php

/*
|--------------------------------------------------------------------------
| CORS: quali siti possono chiamare l'API dal browser
|--------------------------------------------------------------------------
| Il sito (su Vercel) e l'API stanno su indirizzi diversi. L'accesso usa un token nell'intestazione e non i cookie,
| quindi non servono credenziali: con CORS_ALLOWED_ORIGINS si può restringere l'elenco al solo sito, ad esempio
| CORS_ALLOWED_ORIGINS=https://amir-taupe.vercel.app (più indirizzi separati da virgola). Senza, è aperto a tutti.
*/
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Disposition'],
    // il browser ricorda per due ore (il massimo che Chrome accetta) che può fare le richieste: ogni indirizzo nuovo dello staff
    // altrimenti costa una richiesta di verifica in più verso l'America prima di quella vera
    'max_age' => 7200,
    'supports_credentials' => false,
];
