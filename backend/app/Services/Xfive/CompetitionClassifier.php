<?php

namespace App\Services\Xfive;

/** Deduce tipo e nome "pulito" di una competizione dal nome pubblicato da XFive. */
final class CompetitionClassifier
{
    public static function kind(string $name): string
    {
        $n = mb_strtolower($name);

        return match (true) {
            str_contains($n, 'coppa di lega') => 'coppa_lega',
            str_contains($n, 'coppa di categoria') => 'coppa_categoria',
            str_contains($n, 'coppa'), str_contains($n, 'cup') => 'coppa',
            str_contains($n, 'memorial'), str_contains($n, 'lisondra') => 'torneo',
            default => 'campionato',
        };
    }

    /** "Cittadella [alessandria]" -> "Cittadella [Alessandria]". */
    public static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        return (string) preg_replace_callback(
            '/\[(.*?)\]/u',
            fn (array $m) => '['.mb_convert_case($m[1], MB_CASE_TITLE).']',
            $name,
        );
    }
}
