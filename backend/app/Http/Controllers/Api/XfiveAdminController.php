<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\SyncRun;
use App\Services\Xfive\Admin\XfiveAdminClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/** L'accesso all'area amministrazione di XFive (solo staff): in che stato è, e una prova di accesso. */
class XfiveAdminController extends Controller
{
    /** Nessuna richiesta a XFive: legge solo configurazione e ultimo esito. */
    public function status(XfiveAdminClient $client): JsonResponse
    {
        $last = SyncRun::where('scope', 'admin')->latest('started_at')->first();
        $until = $client->blockedUntil();

        return $this->ok([
            'configured' => $client->configured(),
            'enabled' => $client->enabled(),
            'blocked_until' => $until !== null ? Carbon::createFromTimestamp($until)->toIso8601String() : null,
            'players_synced' => Player::whereNotNull('xfive_admin_synced_at')->count(),
            'last_run' => $last ? [
                'status' => $last->status,
                'started_at' => $last->started_at?->toIso8601String(),
                'finished_at' => $last->finished_at?->toIso8601String(),
                'stats' => (object) ($last->stats ?? []),
                'error' => $last->error,
            ] : null,
        ]);
    }

    /** Fa un accesso vero a XFive e dice com'è andato, senza leggere nulla. */
    public function check(XfiveAdminClient $client): JsonResponse
    {
        return $this->ok($client->check());
    }
}
