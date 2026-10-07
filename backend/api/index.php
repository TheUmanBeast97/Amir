<?php

/*
|--------------------------------------------------------------------------
| Punto d'ingresso di Vercel (runtime vercel-php)
|--------------------------------------------------------------------------
| Su Vercel ogni richiesta arriva qui. Il disco è in sola lettura tranne /tmp, e non esiste un file .env:
| le variabili vengono dal pannello di Vercel. Qui si sistemano solo le cose che Laravel deve sapere per girare
| in questo ambiente, poi si passa la richiesta al solito public/index.php.
*/

// Tutto ciò che Laravel scrive (log, viste compilate, elenco dei pacchetti) va in /tmp.
$tmp = sys_get_temp_dir();
foreach (['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $dir) {
    if (! is_dir("{$tmp}/{$dir}")) {
        @mkdir("{$tmp}/{$dir}", 0775, true);
    }
}

// Valori predefiniti per il server: ciò che è già impostato nel pannello di Vercel non si tocca.
$defaults = [
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'LOG_CHANNEL' => 'stderr',                        // i log si leggono nel pannello di Vercel
    'CACHE_STORE' => 'database',                      // i limiti di tentativi devono valere per tutte le istanze
    'SESSION_DRIVER' => 'array',                      // l'accesso usa token, non sessioni
    'QUEUE_CONNECTION' => 'sync',
    'LARAVEL_STORAGE_PATH' => "{$tmp}/storage",
    'VIEW_COMPILED_PATH' => "{$tmp}/storage/framework/views",
    'APP_SERVICES_CACHE' => "{$tmp}/services.php",
    'APP_PACKAGES_CACHE' => "{$tmp}/packages.php",
    'APP_CONFIG_CACHE' => "{$tmp}/config.php",
    'APP_ROUTES_CACHE' => "{$tmp}/routes.php",
    'APP_EVENTS_CACHE' => "{$tmp}/events.php",
];

foreach ($defaults as $key => $value) {
    if (getenv($key) === false || getenv($key) === '') {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

// Laravel deve vedere l'indirizzo vero della richiesta (/api/v1/...): senza questo, il fatto che questo file stia
// nella cartella "api" gli farebbe togliere «/api» dall'indirizzo e nessuna rotta verrebbe trovata.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/../public/index.php';

require __DIR__.'/../public/index.php';
