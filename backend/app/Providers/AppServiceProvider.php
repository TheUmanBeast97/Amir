<?php

namespace App\Providers;

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
        // Accesso: i tentativi si contano per account oltre che per indirizzo IP. Il limite per account non si aggira cambiando
        // IP, né falsificando l'intestazione X-Forwarded-For se il server fosse raggiungibile senza passare dal proxy.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(6)->by('login-account:'.Str::lower((string) $request->input('email'))),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);
    }
}
