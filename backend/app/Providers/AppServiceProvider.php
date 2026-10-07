<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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
        //
    }
}
