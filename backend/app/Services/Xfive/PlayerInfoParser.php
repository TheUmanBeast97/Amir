<?php

namespace App\Services\Xfive;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Legge il profilo globale di un giocatore (/it/player-info/{id}/{slug}/):
 * età, nazionalità e, per ogni squadra (club) in cui ha giocato, i tornei
 * con stagione e formato.
 */
final class PlayerInfoParser
{
    /**
     * @return array{
     *   age:?int, nationality:?string,
     *   clubs: array<int, array{club_id:int, name:string, role:?string, tournaments: array<int, array{id:int,name:string,season:?string,format:?int}>}>
     * }
     */
    public function parse(string $html): array
    {
        $html = $this->utf8($html);
        $crawler = new Crawler($html);

        $values = $crawler->filter('dl.dl-horizontal dd')->each(fn (Crawler $n) => trim($n->text('')));
        $age = isset($values[0]) && preg_match('/^\d+$/', $values[0]) ? (int) $values[0] : null;
        $nationality = isset($values[1]) && $values[1] !== '' ? $values[1] : null;

        $clubs = [];
        $current = null;

        $crawler->filter('#wl-playedtournament-container .wl-tournamentinfo-container')->each(
            function (Crawler $node) use (&$clubs, &$current) {
                $link = $node->filter('h4 a');
                if (! $link->count()) {
                    return;
                }

                $href = (string) $link->attr('href');
                $name = trim($link->text(''));
                $detail = $node->filter('span')->count() ? trim($node->filter('span')->text('')) : '';

                if (preg_match('#/team-h/(\d+)/#', $href, $m)) {
                    $clubs[] = [
                        'club_id' => (int) $m[1],
                        'name' => $name,
                        'role' => $detail !== '' ? $detail : null,
                        'tournaments' => [],
                    ];
                    $current = array_key_last($clubs);

                    return;
                }

                if ($current !== null && preg_match('#/tournament/(\d+)/#', $href, $m)) {
                    // "2025/2026 - Calcio a 7"
                    $season = preg_match('#(\d{4}/\d{4})#', $detail, $s) ? $s[1] : null;
                    $format = preg_match('/Calcio a (\d+)/i', $detail, $f) ? (int) $f[1] : null;

                    $clubs[$current]['tournaments'][] = [
                        'id' => (int) $m[1],
                        'name' => $name,
                        'season' => $season,
                        'format' => $format,
                    ];
                }
            },
        );

        return ['age' => $age, 'nationality' => $nationality, 'clubs' => $clubs];
    }

    /** Alcune pagine di XFive sono in Windows-1252 pur dichiarando UTF-8. */
    private function utf8(string $html): string
    {
        return mb_check_encoding($html, 'UTF-8') ? $html : mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
    }
}
