<?php

use Illuminate\Support\Facades\Route;

// L'app è solo API: la radice dice cos'è e dove guardare (nessuna pagina HTML).
Route::get('/', function () {
    return response()->json([
        'app' => config('app.name'),
        'status' => 'ok',
        'api' => url('/api/v1'),
        'try' => [
            'home' => url('/api/v1/public/home'),
            'standings' => url('/api/v1/public/standings'),
            'roster' => url('/api/v1/public/roster'),
            'history' => url('/api/v1/public/history'),
            'calendar_ics' => url('/api/v1/public/calendar.ics'),
        ],
        'contract' => 'docs/api-types.ts',
    ]);
});
