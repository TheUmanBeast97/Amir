<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncRun;
use App\Services\Xfive\XfiveRoutine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SyncController extends Controller
{
    /**
     * Avvia un aggiornamento da XFive (scope: vedi XfiveRoutine).
     *
     * In locale parte dopo la risposta (può richiedere qualche decina di secondi): la risposta contiene la corsa
     * "running", lo stato si legge poi da GET /sync/runs. Sul server (Vercel) dopo la risposta non si può più lavorare,
     * quindi l'aggiornamento si fa subito, entro un tempo massimo, e la risposta contiene già l'esito (con "remaining" nelle
     * statistiche se c'è ancora da fare).
     */
    public function run(Request $request, XfiveRoutine $routine): JsonResponse
    {
        $data = $request->validate(['scope' => ['required', Rule::in(XfiveRoutine::SCOPES)]]);
        $inline = (bool) config('amir.sync.inline');

        // una corsa rimasta "in corso" perché la richiesta è stata interrotta non deve bloccare per sempre
        $already = SyncRun::where('scope', $data['scope'])
            ->where('status', 'running')
            ->where('started_at', '>=', now()->subMinutes($inline ? 2 : 15))
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

        if ($inline) {
            set_time_limit(0);
            $routine->run($data['scope'], $run, (float) config('amir.sync.budget'));

            return $this->ok($this->present($run->refresh()));
        }

        dispatch(function () use ($run, $data, $routine) {
            set_time_limit(0);
            $routine->run($data['scope'], $run, (float) config('amir.sync.budget'));
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
