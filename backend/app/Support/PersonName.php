<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Confronto e scomposizione dei nomi come li scrive XFive ("Cognome Nome"). */
final class PersonName
{
    private const PARTICLES = ['di', 'de', 'del', 'della', 'dal', 'dalla', 'da', 'lo', 'la', 'le', 'dei', 'degli', 'van', 'von', 'el', 'al', 'mc', 'mac'];

    /** @return array<int, string> parole in minuscolo, senza accenti né punteggiatura */
    public static function tokens(string $text): array
    {
        $clean = Str::of($text)->ascii()->lower()->replaceMatches('/[^a-z0-9 ]+/', ' ')->squish()->toString();

        return $clean === '' ? [] : explode(' ', $clean);
    }

    /** Chiave indipendente dall'ordine delle parole: "Rossi Mario" e "Mario Rossi" coincidono. */
    public static function key(string $text): string
    {
        $tokens = self::tokens($text);
        sort($tokens);

        return implode(' ', $tokens);
    }

    /** @param array<int,string> $small @param array<int,string> $big */
    public static function subset(array $small, array $big): bool
    {
        return $small !== [] && array_diff($small, $big) === [];
    }

    /**
     * Divide "Fracchia Edoardo Giovanni" in [cognome, nome]. Lo slug del link
     * ("edoardo-giovanni-fracchia", prima i nomi poi il cognome) toglie ogni dubbio;
     * senza slug si assume che il cognome sia la prima parola (due, se è un "Di", "De"...).
     *
     * @return array{0:string,1:string}
     */
    public static function split(string $display, ?string $slug = null): array
    {
        $parts = preg_split('/\s+/u', trim($display)) ?: [];
        if (count($parts) < 2) {
            return [trim($display), ''];
        }

        if ($slug) {
            $wanted = explode('-', $slug);
            for ($k = 1; $k < count($parts); $k++) {
                $surname = array_slice($parts, 0, $k);
                $given = array_slice($parts, $k);
                $candidate = explode('-', implode('-', array_map(fn (string $p) => Str::slug($p), [...$given, ...$surname])));

                if ($candidate === $wanted) {
                    return [implode(' ', $surname), implode(' ', $given)];
                }
            }
        }

        $k = (in_array(mb_strtolower($parts[0]), self::PARTICLES, true) && count($parts) > 2) ? 2 : 1;

        return [implode(' ', array_slice($parts, 0, $k)), implode(' ', array_slice($parts, $k))];
    }
}
