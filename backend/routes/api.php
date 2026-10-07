<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\FriendlyController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 - contratto in docs/api-types.ts
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function () {

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    // ---- Pubblico: nessun login, solo dati che si possono mostrare a tutti ----
    Route::prefix('public')->group(function () {
        Route::get('/home', [PublicController::class, 'home']);
        Route::get('/matches', [MatchController::class, 'index']);
        Route::get('/standings', [PublicController::class, 'standings']);
        Route::get('/roster', [PublicController::class, 'roster']);
        Route::get('/calendar.ics', [PublicController::class, 'calendar']);
        Route::get('/history', [PublicController::class, 'history']);
        Route::get('/history/competitions/{competition}', [PublicController::class, 'historyCompetition']);
        Route::get('/head-to-head/{team}', [PublicController::class, 'headToHead']);
        Route::get('/badges/{team}', [PublicController::class, 'badge']);
        Route::get('/players/{player}', [PublicController::class, 'playerProfile']);
        Route::get('/players/{player}/photo', [PublicController::class, 'photo']);
        Route::get('/stats/career', [PublicController::class, 'careerStats']);
        Route::get('/matches/{game}', [PublicController::class, 'match']);
    });

    // ---- Giocatore: link personale, nessuna password ----
    Route::prefix('me/{token}')->middleware('throttle:60,1')->group(function () {
        Route::get('/', [MeController::class, 'show']);
        Route::post('/events/{event}/rsvp', [MeController::class, 'rsvp']);
    });

    // ---- Admin: token Bearer (Sanctum) ----
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        Route::get('/dashboard', [DashboardController::class, 'show']);

        Route::post('/players/import', [PlayerController::class, 'import']);
        Route::post('/players/{player}/scout', [PlayerController::class, 'scout'])->middleware('throttle:20,1');
        Route::put('/players/{player}/scout', [PlayerController::class, 'saveScout']);
        Route::apiResource('players', PlayerController::class);

        Route::get('/matches', [MatchController::class, 'index']);
        Route::get('/matches/{game}', [MatchController::class, 'show']);
        Route::get('/matches/{game}/lineup', [MatchController::class, 'lineup']);
        Route::patch('/matches/{game}', [MatchController::class, 'update']);
        Route::put('/matches/{game}/callups', [MatchController::class, 'saveCallups']);
        Route::put('/matches/{game}/lineup', [MatchController::class, 'saveLineup']);
        Route::put('/matches/{game}/stats', [MatchController::class, 'saveStats']);
        Route::post('/matches/{game}/caption', [MatchController::class, 'caption'])->middleware('throttle:30,1');

        Route::apiResource('friendlies', FriendlyController::class)->except('show')->parameters(['friendlies' => 'game']);
        Route::apiResource('events', EventController::class)->except('show');
        Route::get('/events/{event}/responses', [EventController::class, 'responses']);
        Route::put('/events/{event}/responses/{player}', [EventController::class, 'setResponse']);
        Route::post('/events/{event}/remind', [EventController::class, 'remind']);

        Route::get('/charges', [FinanceController::class, 'charges']);
        Route::post('/charges', [FinanceController::class, 'storeCharge']);
        Route::get('/charges/{charge}', [FinanceController::class, 'showCharge']);
        Route::delete('/charges/{charge}', [FinanceController::class, 'destroyCharge']);
        Route::post('/charges/{charge}/assign', [FinanceController::class, 'assign']);
        Route::post('/player-charges/{playerCharge}/payments', [FinanceController::class, 'addPayment']);
        Route::delete('/payments/{payment}', [FinanceController::class, 'destroyPayment']);
        Route::get('/finance/summary', [FinanceController::class, 'summary']);
        Route::get('/finance/players/{player}', [FinanceController::class, 'playerBalance']);

        Route::get('/stats/attendance', [StatsController::class, 'attendance']);

        // Modulistica XFive: analisi dei documenti, file originali e domande
        Route::get('/documents', [DocumentController::class, 'index']);
        Route::get('/documents/{slug}/file', [DocumentController::class, 'file'])->where('slug', '[a-z0-9-]+');
        Route::post('/documents/ask', [DocumentController::class, 'ask'])->middleware('throttle:20,1');

        Route::post('/sync/xfive', [SyncController::class, 'run']);
        Route::get('/sync/runs', [SyncController::class, 'runs']);
    });
});
