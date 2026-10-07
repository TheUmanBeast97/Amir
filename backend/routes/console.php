<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Aggiornamento da XFive: calendario e risultati ogni 3 ore, storico una volta a settimana
Schedule::command('xfive:sync current')->everyThreeHours()->withoutOverlapping();
Schedule::command('xfive:sync history')->weeklyOn(1, '04:00')->withoutOverlapping();
// stemmi nuovi e statistiche dei giocatori (una volta al giorno, di notte)
Schedule::command('xfive:badges')->dailyAt('05:00')->withoutOverlapping();
Schedule::command('xfive:players --stats-only')->dailyAt('05:30')->withoutOverlapping();
// arbitro, distinta e marcatori delle partite appena giocate
Schedule::command('xfive:matches --recent')->dailyAt('06:00')->withoutOverlapping();
