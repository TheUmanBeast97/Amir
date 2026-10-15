<?php

namespace App\Services\Xfive\Admin;

use App\Models\User;
use App\Models\XfiveSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Dove si tiene l'accesso a XFive di ogni persona dello staff: solo i cookie di sessione, cifrati con la chiave dell'app, con una
 * scadenza che si sposta in avanti a ogni uso. La password non arriva mai qui. Dopo alcuni tentativi sbagliati di fila si aspetta
 * un po' (una password sbagliata ripetuta potrebbe far bloccare l'account su XFive).
 */
final class XfiveSessionStore
{
    /** La sessione, se c'è e non è scaduta (una scaduta si cancella). */
    public function current(User $user): ?XfiveSession
    {
        $session = XfiveSession::where('user_id', $user->id)->first();

        if ($session && $session->expires_at->isPast()) {
            $session->delete();

            return null;
        }

        return $session;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cookies
     */
    public function open(User $user, string $email, array $cookies): XfiveSession
    {
        XfiveSession::where('expires_at', '<', now())->delete();

        return XfiveSession::updateOrCreate(['user_id' => $user->id], [
            'email_hint' => $this->hint($email),
            'cookies' => Crypt::encryptString((string) json_encode($cookies)),
            'expires_at' => $this->expiry(),
            'last_used_at' => now(),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function cookies(XfiveSession $session): array
    {
        $json = json_decode(Crypt::decryptString($session->cookies), true);

        return is_array($json) ? $json : [];
    }

    /**
     * Dopo un uso: i cookie aggiornati (XFive può cambiarli) e la scadenza spostata avanti.
     *
     * @param  array<int, array<string, mixed>>  $cookies
     */
    public function touch(XfiveSession $session, array $cookies): void
    {
        $session->update([
            'cookies' => Crypt::encryptString((string) json_encode($cookies)),
            'expires_at' => $this->expiry(),
            'last_used_at' => now(),
        ]);
    }

    /**
     * Cancella la sessione e restituisce i suoi cookie (per uscire anche da XFive).
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function close(User $user): ?array
    {
        $session = XfiveSession::where('user_id', $user->id)->first();
        if (! $session) {
            return null;
        }

        $cookies = $this->cookies($session);
        $session->delete();

        return $cookies;
    }

    /** Fino a quando non si riprova dopo troppi accessi rifiutati (timestamp), o null. */
    public function blockedUntil(User $user): ?int
    {
        $until = (int) Cache::get($this->blockKey($user), 0);

        return $until > time() ? $until : null;
    }

    public function failed(User $user): void
    {
        $key = $this->failKey($user);
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, now()->addHour());

        if ($count >= (int) config('amir.xfive_admin.login_max_failures')) {
            $minutes = max(1, (int) config('amir.xfive_admin.login_block_minutes'));
            Cache::put($this->blockKey($user), time() + $minutes * 60, now()->addMinutes($minutes));
            Cache::forget($key);
        }
    }

    public function succeeded(User $user): void
    {
        Cache::forget($this->failKey($user));
        Cache::forget($this->blockKey($user));
    }

    private function expiry(): \DateTimeInterface
    {
        return now()->addMinutes(max(5, (int) config('amir.xfive_admin.session_minutes')));
    }

    /** «e***@b4web.biz»: abbastanza per riconoscere l'account, non per ricostruire l'indirizzo. */
    private function hint(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return $domain === '' ? '***' : mb_substr($local, 0, 1).'***@'.$domain;
    }

    private function failKey(User $user): string
    {
        return "amir:xfive-login-failed:{$user->id}";
    }

    private function blockKey(User $user): string
    {
        return "amir:xfive-login-blocked:{$user->id}";
    }
}
