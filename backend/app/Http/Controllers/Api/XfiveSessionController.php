<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsToXfiveErrors;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Xfive\Admin\SheetService;
use App\Services\Xfive\Admin\XfiveAccess;
use App\Services\Xfive\Admin\XfiveAdminException;
use App\Services\Xfive\Admin\XfiveSessionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Collega XFive» / «Esci da XFive» nella Staff Area. La persona digita email e password del proprio account XFive: la password
 * serve solo a fare il login e non viene salvata (né nel database né nei log); si conserva il cookie di sessione, cifrato.
 */
class XfiveSessionController extends Controller
{
    use RespondsToXfiveErrors;

    public function show(Request $request, XfiveSessionStore $store): JsonResponse
    {
        return $this->ok($this->status($request->user(), $store));
    }

    public function connect(Request $request, XfiveAccess $access, XfiveSessionStore $store, SheetService $sheets): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $user = $request->user();

        if (($until = $store->blockedUntil($user)) !== null) {
            $minutes = max(1, (int) ceil(($until - time()) / 60));

            return $this->xfiveError(new XfiveAdminException('blocked', "Troppi accessi rifiutati da XFive: per sicurezza riprova fra circa {$minutes} minuti."));
        }

        try {
            $cookies = $access->login($data['email'], $data['password']);
        } catch (XfiveAdminException $e) {
            if ($e->state === 'login_failed') {
                $store->failed($user);
            }

            return $this->xfiveError($e);
        }

        $store->succeeded($user);
        $store->open($user, $data['email'], $cookies);

        // appena collegato, i giocatori senza id XFive lo ricevono da soli; se non riesce, il collegamento resta valido
        // e l'abbinamento si rifà con il pulsante
        $link = null;
        $linkError = null;
        try {
            $link = $sheets->linkPlayers($user);
        } catch (XfiveAdminException $e) {
            $linkError = $e->getMessage();
        }

        return $this->ok($this->status($user, $store) + ['link' => $link, 'link_error' => $linkError]);
    }

    /** Abbina i giocatori della rosa a XFive (assegna l'id a chi non ce l'ha). */
    public function linkPlayers(Request $request, SheetService $sheets): JsonResponse
    {
        try {
            return $this->ok($sheets->linkPlayers($request->user()));
        } catch (XfiveAdminException $e) {
            return $this->xfiveError($e);
        }
    }

    public function disconnect(Request $request, XfiveAccess $access, XfiveSessionStore $store): JsonResponse
    {
        $cookies = $store->close($request->user());
        if ($cookies !== null) {
            $access->logout($cookies);
        }

        return $this->ok($this->status($request->user(), $store));
    }

    /** @return array{connected: bool, email_hint: ?string, expires_at: ?string, blocked_until: ?string} */
    private function status(User $user, XfiveSessionStore $store): array
    {
        $session = $store->current($user);
        $until = $store->blockedUntil($user);

        return [
            'connected' => $session !== null,
            'email_hint' => $session?->email_hint,
            'expires_at' => $session?->expires_at->toIso8601String(),
            'blocked_until' => $until !== null ? now()->setTimestamp($until)->toIso8601String() : null,
        ];
    }
}
