<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncRun;
use App\Services\Xfive\XfiveSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SyncController extends Controller
{
    /**
     * Avvia l'aggiornamento da XFive dopo aver risposto (può richiedere
     * qualche decina di secondi): la risposta contiene la corsa "running",
     * lo stato si legge poi da GET /sync/runs.
     */
    public function run(Request $request): JsonResponse
    {
        $data = $request->validate(['scope' => ['required', Rule::in(['current', 'history'])]]);

        $already = SyncRun::where('scope', $data['scope'])
            ->where('status', 'running')
            ->where('started_at', '>=', now()->subMinutes(15))
            ->first();

        if ($already) {
            return $this->ok($this->present($already), 202);
        }

        $run = SyncRun::create([
            'scope' => $data['scope'],
            'status' => 'running',
            'started_at' => now(),
            'stats' => [],
        ]);

        dispatch(function () use ($run, $data) {
            set_time_limit(0);
            app(XfiveSyncService::class)->run($data['scope'], $run);
        })->afterResponse();

        return $this->ok($this->present($run), 202);
    }

    public function runs(): JsonResponse
    {
        return $this->ok(SyncRun::latest('started_at')->limit(20)->get()->map(fn (SyncRun $r) => $this->present($r))->all());
    }

    /** @return array<string, mixed> */
    private function present(SyncRun $run): array
    {
        return [
            'id' => $run->id,
            'scope' => $run->scope,
            'status' => $run->status,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'stats' => (object) ($run->stats ?? []),
            'error' => $run->error,
        ];
    }
}
