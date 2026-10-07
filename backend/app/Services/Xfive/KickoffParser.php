<?php

namespace App\Services\Xfive;

use Carbon\CarbonImmutable;

/**
 * Le pagine XFive scrivono la data come "gio 15/10 20:00", senza anno.
 * L'anno si deduce dalla stagione: da luglio a dicembre vale l'anno di inizio
 * ("2026/2027" -> 2026), da gennaio a giugno quello di fine (2027).
 */
final class KickoffParser
{
    public static function parse(?string $raw, string $season): ?CarbonImmutable
    {
        if ($raw === null || ! preg_match('#(\d{1,2})/(\d{1,2})\s+(\d{1,2}):(\d{2})#', $raw, $m)) {
            return null;
        }

        $startYear = (int) explode('/', $season)[0];
        if ($startYear < 2000) {
            return null;
        }

        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = $month >= 7 ? $startYear : $startYear + 1;

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, (int) $m[3], (int) $m[4], 0, config('app.timezone'));
    }
}
