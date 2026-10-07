<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Xfive\XfiveRoutine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aggiornamenti automatici da XFive. Li chiama la pianificazione di Vercel (vercel.json, "crons") con
 * «Authorization: Bearer <CRON_SECRET>»; stesso segreto per chiunque altro voglia lanciarli (es. un servizio esterno ogni ora).
 * Senza CRON_SECRET nelle variabili d'ambiente gli indirizzi non esistono.
 */
class CronController extends Controller
{
    public function run(Request $request, string $scope, XfiveRoutine $routine): JsonResponse
    {
        $secret = (string) config('amir.cron_secret');
        abort_if($secret === '', 404);
        abort_unless(hash_equals('Bearer '.$secret, (string) $request->header('Authorization')), 401);

        set_time_limit(0);
        $run = $routine->run($scope, null, (float) config('amir.sync.budget'));

        return $this->ok([
            'scope' => $run->scope,
            'status' => $run->status,
            'stats' => (object) ($run->stats ?? []),
            'error' => $run->error,
        ]);
    }
}
