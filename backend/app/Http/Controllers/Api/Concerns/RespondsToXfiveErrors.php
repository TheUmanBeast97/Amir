<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Services\Xfive\Admin\XfiveAdminException;
use Illuminate\Http\JsonResponse;

/**
 * Come un problema con XFive diventa una risposta dell'API: sempre `{ message, state }` e mai un 401 (che nell'app vuol dire
 * «sei uscito dalla Staff Area»: qui al massimo manca il collegamento a XFive).
 */
trait RespondsToXfiveErrors
{
    protected function xfiveError(XfiveAdminException $e): JsonResponse
    {
        $status = match ($e->state) {
            'login_failed', 'rejected', 'invalid' => 422,
            'blocked' => 429,
            'unreachable', 'unexpected_page' => 502,
            default => 409, // not_connected, session_expired, not_open, not_configured, disabled
        };

        return response()->json(['message' => $e->getMessage(), 'state' => $e->state], $status);
    }
}
