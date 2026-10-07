<?php

namespace App\Services\Xfive;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Legge la pagina pubblica di una partita (/it/match/{id}/{slug}/).
 *
 * Contiene, per entrambe le squadre: la distinta dei giocatori (chi era presente),
 * con icone per gol ("Goal" + "x2"), ammonizioni, espulsioni e la stella
 * "Miglior giocatore"; in testa ci sono anche arbitro, campo e l'elenco dei marcatori.
 * Se la partita non ha una distinta (es. tabelloni a eliminazione) le liste sono vuote.
 */
final class MatchPageParser
{
    private const DISCIPLINE = ['1' => 'yellow', '2' => 'red'];

    /**
     * @return array{
     *   referee:?string, venue:?string, home_score:?int, away_score:?int,
     *   home:array{lineup:array<int,array<string,mixed>>,scorers:array<int,array<string,mixed>>},
     *   away:array{lineup:array<int,array<string,mixed>>,scorers:array<int,array<string,mixed>>}
     * }
     */
    public function parse(string $html): array
    {
        $page = new Crawler($html);

        $referee = null;
        $venue = null;
        $page->filter('.match-container > .info')->each(function (Crawler $info) use (&$referee, &$venue) {
            $icon = $info->filter('img')->count() ? (string) $info->filter('img')->first()->attr('src') : '';
            $text = $this->clean($info->text(''));

            if (str_contains($icon, 'referee')) {
                $referee = $text !== '' ? $text : null;
            } elseif (str_contains($icon, 'stadium')) {
                $venue = $text !== '' ? $text : null;
            }
        });

        [$homeScore, $awayScore] = $this->score($page);

        return [
            'referee' => $referee,
            'venue' => $venue,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'home' => ['lineup' => $this->lineup($page, '.lineup_a'), 'scorers' => $this->scorers($page, '.scorer_a')],
            'away' => ['lineup' => $this->lineup($page, '.lineup_b'), 'scorers' => $this->scorers($page, '.scorer_b')],
        ];
    }

    /** @return array{0:?int,1:?int} */
    private function score(Crawler $page): array
    {
        $el = $page->filter('.team-score-container .score');
        if (! $el->count() || ! preg_match('/(\d+)\s*-\s*(\d+)/', $el->text(''), $m)) {
            return [null, null];
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /** @return array<int, array<string, mixed>> */
    private function lineup(Crawler $page, string $side): array
    {
        $container = $page->filter('#team-lineup '.$side);
        if (! $container->count()) {
            return [];
        }

        $players = [];
        $container->filter('.player')->each(function (Crawler $node) use (&$players) {
            $link = $node->filter('.player-name a');
            if (! $link->count()) {
                return;
            }

            $ref = preg_match('#/player/(\d+)/#', (string) $link->attr('href'), $m) ? (int) $m[1] : null;
            $photo = $node->filter('.player-img img')->count() ? (string) $node->filter('.player-img img')->attr('src') : null;
            $entry = [
                'ref' => $ref,
                'name' => $this->clean($link->text('')),
                'slug' => preg_match('#/player/\d+/([^/]+)/#', (string) $link->attr('href'), $m) ? $m[1] : null,
                'goals' => 0,
                'yellow' => 0,
                'red' => 0,
                'mvp' => str_contains((string) $node->attr('class'), 'top-player') || $node->filter('.top-icon img')->count() > 0,
                'photo' => ($photo && ! str_contains(basename($photo), 'ph_')) ? $photo : null,
                'other' => [],
            ];

            $stat = $node->filter('.player-stat');
            $statHtml = $stat->count() ? $stat->html('') : '';
            // <img ... score/1.png title="Goal" />x2   |   <img ... discipline/1.png title="Ammonizioni" />
            if (preg_match_all('#<img[^>]*?/(score|discipline)/(\d+)\.png[^>]*?title="([^"]*)"[^>]*>\s*(?:x(\d+))?#i', $statHtml, $icons, PREG_SET_ORDER)) {
                foreach ($icons as $icon) {
                    $count = isset($icon[4]) && $icon[4] !== '' ? (int) $icon[4] : 1;

                    if ($icon[1] === 'score' && $icon[2] === '1') {
                        $entry['goals'] += $count;
                    } elseif ($icon[1] === 'discipline' && isset(self::DISCIPLINE[$icon[2]])) {
                        $entry[self::DISCIPLINE[$icon[2]]] += $count;
                    } else {
                        // icone che non conosciamo (autogol, rigori...): le conserviamo per non perdere informazione
                        $entry['other'][] = ['title' => html_entity_decode($icon[3]), 'count' => $count];
                    }
                }
            }

            $players[] = $entry;
        });

        return $players;
    }

    /**
     * Elenco marcatori in testa alla pagina: "Cognome Nome (2)" per la squadra di casa,
     * "(2) Cognome Nome" per l'ospite; il numero manca se il gol è uno solo.
     *
     * @return array<int, array{ref:?int,name:string,goals:int}>
     */
    private function scorers(Crawler $page, string $side): array
    {
        $container = $page->filter('.team-scorer-set-container '.$side);
        if (! $container->count()) {
            return [];
        }

        $out = [];
        foreach (preg_split('#<br\s*/?>#i', $container->html('')) ?: [] as $chunk) {
            if (! preg_match('#<a[^>]*href="[^"]*/player/(\d+)/[^"]*"[^>]*>(.*?)</a>#is', $chunk, $m)) {
                continue;
            }

            $rest = trim(str_replace($m[0], '', $chunk));
            $out[] = [
                'ref' => (int) $m[1],
                'name' => $this->clean(strip_tags($m[2])),
                'goals' => preg_match('/\((\d+)\)/', $rest, $n) ? (int) $n[1] : 1,
            ];
        }

        return $out;
    }

    private function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(str_replace("\u{00a0}", ' ', $text), ENT_QUOTES | ENT_HTML5)));
    }
}
