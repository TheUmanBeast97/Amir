<?php

namespace App\Providers;

use App\Services\DatabaseBootstrap;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // un solo client XFive per richiesta/comando: la pausa fra le chiamate resta valida
        $this->app->singleton(\App\Services\Xfive\XfiveClient::class);

        // chi scrive i testi (didascalie, schede dei giocatori): Gemini; nei test si sostituisce con uno finto
        $this->app->singleton(\App\Services\Ai\TextGenerator::class, \App\Services\Ai\GeminiTextGenerator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Accesso: tre tetti al minuto. Dallo stesso indirizzo bastano pochi tentativi su un account. Chi cambia (o falsifica
        // con X-Forwarded-For) l'indirizzo a ogni tentativo trova comunque un tetto per account, più alto: abbastanza basso
        // da rendere inutile indovinare la password, abbastanza alto perché un estraneo non possa chiudere fuori il vero
        // utente con pochi tentativi sbagliati.
        // Copie di sicurezza: ognuna ha il suo contatore (con i limiti "throttle:5,1" tutte le rotte di un utente ne dividono uno solo).
        RateLimiter::for('backup', fn (Request $request) => Limit::perMinute(10)->by('backup:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('restore', fn (Request $request) => Limit::perMinute(5)->by('restore:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('login', function (Request $request) {
            $account = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(6)->by('login-account-ip:'.$account.'|'.$request->ip()),
                Limit::perMinute(30)->by('login-account:'.$account),
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
            ];
        });

        // sul server il primo avvio può trovare il database ancora da preparare (tabelle, amministratore): se serve lo si prepara qui
        if (config('amir.self_migrate') && ! $this->app->runningInConsole()) {
            $this->app->make(DatabaseBootstrap::class)->ensureReady();
        }
    }
}
