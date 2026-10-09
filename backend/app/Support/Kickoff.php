<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * La data di una partita XFive da «kickoff_raw» («lun 12/10 21:00», senza anno) e dalla stagione («2026/2027»):
 * i mesi da luglio a dicembre stanno nel primo anno, da gennaio a giugno nel secondo. Fuso orario Europe/Rome.
 */
final class Kickoff
{
    public static function parse(?string $raw, string $seasonLabel): ?Carbon
    {
        if ($raw === null || ! preg_match('~(\d{1,2})/(\d{1,2})(?:\s+(\d{1,2}):(\d{2}))?~', $raw, $m)) {
            return null;
        }
        if (! preg_match('~(\d{4})/(\d{4})~', $seasonLabel, $s)) {
            return null;
        }

        $day = (int) $m[1];
        $month = (int) $m[2];
        $hour = isset($m[3]) ? (int) $m[3] : 0;
        $minute = isset($m[4]) ? (int) $m[4] : 0;
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $minute > 59) {
            return null;
        }
        $year = $month >= 7 ? (int) $s[1] : (int) $s[2];
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return Carbon::create($year, $month, $day, $hour, $minute, 0, 'Europe/Rome');
    }
}
