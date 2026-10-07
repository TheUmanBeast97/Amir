<?php

namespace App\Services\Xfive;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Legge il frammento HTML (campo "html" del JSON di team.php) che elenca i
 * tornei disputati da un club in una stagione.
 */
final class ClubHistoryParser
{
    /**
     * @return array<int, array{id:int, slug:string, name:string, format:int, url:string}>
     */
    public function parse(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $tournaments = [];
        $crawler = new Crawler($html);

        $crawler->filter('.wl-tournamentinfo-container')->each(function (Crawler $node) use (&$tournaments) {
            $link = $node->filter('h4 a');
            if (! $link->count()) {
                return;
            }

            $href = (string) $link->attr('href');
            if (! preg_match('#/tournament/(\d+)/([^/]+)/#', $href, $m)) {
                return;
            }

            $format = preg_match('/(\d+)/', $node->filter('span')->count() ? $node->filter('span')->text('') : '', $f)
                ? (int) $f[1]
                : 8;

            $tournaments[(int) $m[1]] = [
                'id' => (int) $m[1],
                'slug' => $m[2],
                'name' => trim($link->text('')),
                'format' => in_array($format, [7, 8], true) ? $format : 8,
                'url' => $href,
            ];
        });

        return array_values($tournaments);
    }
}
