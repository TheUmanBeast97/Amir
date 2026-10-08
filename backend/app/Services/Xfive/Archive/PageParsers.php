<?php

namespace App\Services\Xfive\Archive;

use Symfony\Component\DomCrawler\Crawler;

/**
 * I lettori delle pagine e delle risposte interne di XFive che servono all'archivio. Sono tutti «puri»: HTML dentro,
 * array fuori, nessuna richiesta. La struttura è quella osservata sul sito l'8/10/2026 (vedi json/map.json nell'archivio).
 */
final class PageParsers
{
    /** XFive manda a volte pagine in Windows-1252 («CAFFÈ» arriva rotto): si riporta tutto in UTF-8. */
    public static function utf8(string $html): string
    {
        return mb_check_encoding($html, 'UTF-8') ? $html : mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
    }

    private static function text(Crawler $node, string $selector): ?string
    {
        $found = $node->filter($selector);
        if ($found->count() === 0) {
            return null;
        }
        $t = trim(preg_replace('/\s+/', ' ', $found->first()->text('')) ?? '');

        return $t === '' ? null : $t;
    }

    private static function attr(Crawler $node, string $selector, string $attr): ?string
    {
        $found = $node->filter($selector);

        return $found->count() ? ($found->first()->attr($attr) ?: null) : null;
    }

    /** @return array{0:?int, 1:?string} id e slug da un indirizzo come /it/tournament/187/cittadella-alessandria/stream/ */
    public static function idSlug(?string $href, string $kind): array
    {
        if ($href && preg_match('~/'.preg_quote($kind, '~').'/(\d+)(?:/([^/?#]+))?~', $href, $m)) {
            return [(int) $m[1], $m[2] ?? null];
        }

        return [null, null];
    }

    /**
     * L'elenco dei tornei di una stagione (league.php op=23).
     *
     * @return array<int, array{id:int, slug:?string, name:?string, sport:?string, teams:?int, dates:?string, image_url:?string}>
     */
    public static function tournamentList(string $html): array
    {
        $out = [];
        foreach ((new Crawler(self::utf8($html)))->filter('a[href*="/it/tournament/"]') as $a) {
            $node = new Crawler($a);
            [$id, $slug] = self::idSlug($a->getAttribute('href'), 'tournament');
            if ($id === null) {
                continue;
            }
            $info = self::text($node, '.info');
            $teams = $info && preg_match('/(\d+)\s*Squadr/i', $info, $m) ? (int) $m[1] : null;
            $out[$id] = [
                'id' => $id,
                'slug' => $slug,
                'name' => self::text($node, '.name'),
                'sport' => $info ? trim(explode('|', $info)[0]) : null,
                'teams' => $teams,
                'dates' => self::text($node, '.date'),
                'image_url' => self::attr($node, 'img', 'src'),
            ];
        }

        return array_values($out);
    }

    /**
     * L'intestazione di una pagina di torneo (o di una sua sottopagina): null se la pagina non è un torneo.
     *
     * @return array{name:string, category:?string, season_id:?int, season:?string, sport:?string, flyer_url:?string}|null
     */
    public static function tournamentHeader(string $html): ?array
    {
        $c = new Crawler(self::utf8($html));
        $name = self::text($c, '.tournament-title');
        if ($name === null) {
            return null;
        }
        $seasonHref = self::attr($c, '.season-title a', 'href');
        $seasonId = $seasonHref && preg_match('#/season/(\d+)/#', $seasonHref, $m) ? (int) $m[1] : null;
        $season = self::text($c, '.season-title');

        return [
            'name' => $name,
            'category' => self::text($c, '.category-title'),
            'season_id' => $seasonId,
            'season' => $season ? trim(str_ireplace('Stagione', '', $season)) : null,
            'sport' => self::text($c, '.tournament-sport'),
            'flyer_url' => self::attr($c, '.tournament-flyer img', 'src'),
        ];
    }

    /**
     * La classifica (league.php op=20): una o più tabelle (gironi), ognuna con le colonne e le righe.
     *
     * @return array<int, array{group:?string, columns:array<int, array{key:string, label:?string}>, rows:array<int, array{position:?int, name:?string, badge_url:?string, values:array<string, int|string>}>}>
     */
    public static function standings(string $html): array
    {
        $tables = [];
        foreach ((new Crawler(self::utf8($html)))->filter('.tables-container') as $container) {
            $t = new Crawler($container);
            $columns = [];
            foreach ($t->filter('.right-table .tables-header .col-data') as $h) {
                $columns[] = ['key' => trim($h->textContent), 'label' => $h->getAttribute('title') ?: null];
            }
            $left = $t->filter('.left-table .tables-body');
            $right = $t->filter('.right-table .tables-body');
            $rows = [];
            foreach ($left as $n => $row) {
                $r = new Crawler($row);
                $values = [];
                if ($right->count() > $n) {
                    foreach ((new Crawler($right->getNode($n)))->filter('.col-data') as $k => $cell) {
                        $key = $columns[$k]['key'] ?? (string) $k;
                        $v = trim($cell->textContent);
                        $values[$key] = is_numeric($v) ? (int) $v : $v;
                    }
                }
                $pos = self::text($r, '.tables-pos');
                $rows[] = [
                    'position' => $pos !== null && is_numeric($pos) ? (int) $pos : null,
                    'name' => self::text($r, '.participant-name') ?? self::text($r, '.tables-main'),
                    'badge_url' => self::attr($r, '.tables-main img', 'src'),
                    'values' => $values,
                ];
            }
            // il titolo del girone, se c'è, sta subito prima della tabella
            $group = null;
            for ($prev = $container->previousSibling; $prev; $prev = $prev->previousSibling) {
                if ($prev->nodeType === XML_ELEMENT_NODE) {
                    $txt = trim(preg_replace('/\s+/', ' ', $prev->textContent) ?? '');
                    $group = $txt !== '' && mb_strlen($txt) < 80 ? $txt : null;
                    break;
                }
            }
            $tables[] = ['group' => $group, 'columns' => $columns, 'rows' => $rows];
        }

        return $tables;
    }

    /**
     * Le squadre di un torneo (league.php op=21).
     *
     * @return array<int, array{id:int, slug:?string, name:?string, badge_url:?string}>
     */
    public static function teamList(string $html): array
    {
        $out = [];
        foreach ((new Crawler(self::utf8($html)))->filter('a[href*="/it/team/"]') as $a) {
            [$id, $slug] = self::idSlug($a->getAttribute('href'), 'team');
            if ($id === null || isset($out[$id])) {
                continue;
            }
            $node = new Crawler($a);
            $out[$id] = [
                'id' => $id,
                'slug' => $slug,
                'name' => self::text($node, '.participant-detail > div:first-child') ?? self::text($node, '.participant-detail'),
                'badge_url' => self::attr($node, 'img', 'src'),
            ];
        }

        return array_values($out);
    }

    /**
     * La pagina di una squadra in un torneo: nome, stemma, club, dirigenti e rosa.
     *
     * @return array{name:?string, badge_url:?string, club_id:?int, club_slug:?string, staff:array<string,string>, players:array<int, array{id:int, slug:?string, name:?string, role:?string, number:?string, photo_url:?string, country:?string}>}|null
     */
    public static function teamPage(string $html): ?array
    {
        $c = new Crawler(self::utf8($html));
        $name = self::text($c, '.team-name h3') ?? self::text($c, '.team-name');
        if ($name === null && $c->filter('.player-container')->count() === 0) {
            return null;
        }
        [$clubId, $clubSlug] = self::idSlug(self::attr($c, 'a#linkToHistory', 'href') ?? self::attr($c, 'a[href*="/it/team-h/"]', 'href'), 'team-h');

        $staff = [];
        foreach ($c->filter('.team-staff-container .info') as $info) {
            $parts = explode(':', trim($info->textContent), 2);
            if (count($parts) === 2) {
                $staff[trim($parts[0])] = trim(preg_replace('/\s+/', ' ', $parts[1]) ?? '');
            }
        }

        $players = [];
        foreach ($c->filter('a[href*="/it/player/"]') as $a) {
            [$id, $slug] = self::idSlug($a->getAttribute('href'), 'player');
            $node = new Crawler($a);
            if ($id === null || $node->filter('.player-container')->count() === 0) {
                continue;
            }
            $players[$id] = [
                'id' => $id,
                'slug' => $slug,
                'name' => self::text($node, '.player-name'),
                'role' => self::text($node, '.player-role'),
                'number' => self::text($node, '.player-number'),
                'photo_url' => self::attr($node, 'img.round-img', 'src'),
                'country' => self::attr($node, 'img.player-country-img', 'title'),
            ];
        }

        $badge = null;
        foreach ($c->filter('img') as $img) {
            if (str_contains((string) $img->getAttribute('src'), '/img/team/badge/')) {
                $badge = $img->getAttribute('src');
                break;
            }
        }

        return ['name' => $name, 'badge_url' => $badge, 'club_id' => $clubId, 'club_slug' => $clubSlug, 'staff' => $staff, 'players' => array_values($players)];
    }

    /**
     * La pagina storica di un club (team-h): nome e i giocatori con il loro profilo globale (player-info).
     *
     * @return array{name:?string, badge_url:?string, players:array<int, array{id:int, slug:?string, name:?string}>}
     */
    public static function clubPage(string $html): array
    {
        $c = new Crawler(self::utf8($html));
        $players = [];
        foreach ($c->filter('a[href*="/it/player-info/"]') as $a) {
            [$id, $slug] = self::idSlug($a->getAttribute('href'), 'player-info');
            if ($id === null || isset($players[$id])) {
                continue;
            }
            $name = trim(preg_replace('/\s+/', ' ', $a->textContent) ?? '');
            $players[$id] = ['id' => $id, 'slug' => $slug, 'name' => $name !== '' ? $name : null];
        }
        $badge = null;
        foreach ($c->filter('img') as $img) {
            if (str_contains((string) $img->getAttribute('src'), '/img/team/badge/')) {
                $badge = $img->getAttribute('src');
                break;
            }
        }

        return ['name' => self::text($c, 'h3') ?? self::text($c, 'title'), 'badge_url' => $badge, 'players' => array_values($players)];
    }

    /**
     * La modulistica di un torneo: i documenti sulla CDN con il testo del link.
     *
     * @return array<int, array{url:string, title:?string}>
     */
    public static function docs(string $html): array
    {
        $out = [];
        foreach ((new Crawler(self::utf8($html)))->filter('a[href*="/doc/"]') as $a) {
            $href = (string) $a->getAttribute('href');
            if (! str_contains($href, 'cdn.enjore.com') || isset($out[$href])) {
                continue;
            }
            $title = trim(preg_replace('/\s+/', ' ', $a->textContent) ?? '');
            $out[$href] = ['url' => $href, 'title' => $title !== '' ? $title : null];
        }

        return array_values($out);
    }
}
