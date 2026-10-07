<?php

namespace App\Services\Xfive;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Legge la pagina stampabile del calendario di un torneo
 * (t-printable.php?t={id}&sk=calendar), già completa in HTML.
 *
 * Ogni partita è un <div id="match-14586" class="tcalendar-match-container
 * played round-647 team-159 team-432 tourn-187 field-2">: l'id è della partita,
 * le due classi team-N sono gli id dei CLUB (casa, ospite).
 */
final class PrintableCalendarParser
{
    /**
     * @return array<int, array{
     *   xfive_match_id:int, round_id:?int, round:?int, round_label:string,
     *   tournament_id:?int, played:bool,
     *   home:array{club_id:int,name:string,badge_url:?string},
     *   away:array{club_id:int,name:string,badge_url:?string},
     *   kickoff_raw:?string, venue:?string, home_score:?int, away_score:?int
     * }>
     */
    public function parse(string $html): array
    {
        $fixtures = [];
        $crawler = new Crawler($html);

        $crawler->filter('.tcalendar-match-container')->each(function (Crawler $node) use (&$fixtures) {
            $fixture = $this->parseNode($node);
            if ($fixture !== null) {
                $fixtures[] = $fixture;
            }
        });

        return $fixtures;
    }

    private function parseNode(Crawler $node): ?array
    {
        $id = (string) $node->attr('id');
        $class = (string) $node->attr('class');

        if (! preg_match('/^match-(\d+)$/', $id, $m)) {
            return null;
        }
        $matchId = (int) $m[1];

        preg_match_all('/\bteam-(\d+)\b/', $class, $teamIds);
        if (count($teamIds[1]) < 2) {
            return null;
        }

        $names = $node->filter('.tmatch-left .team-name')->each(fn (Crawler $n) => trim($n->text('')));
        $badges = $node->filter('.tmatch-left .team-img img')->each(fn (Crawler $n) => $n->attr('src'));
        if (count($names) < 2 || $names[0] === '' || $names[1] === '') {
            return null;
        }

        $top = trim((string) preg_replace('/\s+/', ' ', $node->filter('.tmatch-top')->text('')));
        $round = null;
        if (preg_match('/GIORNATA\s+(\d+)/i', $top, $r)) {
            $round = (int) $r[1];
            $label = $round.'ª giornata';
        } else {
            $label = $top !== '' ? mb_convert_case(mb_strtolower($top), MB_CASE_TITLE) : 'Partita';
        }

        $played = (bool) preg_match('/\bplayed\b/', $class);
        $homeScore = $this->score($node, '.tmatch-right .top');
        $awayScore = $this->score($node, '.tmatch-right .bottom');
        if ($homeScore === null || $awayScore === null) {
            $homeScore = $awayScore = null;
        }

        $center = $node->filter('.tmatch-bottom .center');
        $right = $node->filter('.tmatch-bottom .right');
        $kickoffRaw = $center->count() ? trim($center->text('')) : '';
        $venue = $right->count() ? trim($right->text('')) : '';

        return [
            'xfive_match_id' => $matchId,
            'round_id' => preg_match('/\bround-(\d+)\b/', $class, $x) ? (int) $x[1] : null,
            'round' => $round,
            'round_label' => $label,
            'tournament_id' => preg_match('/\btourn-(\d+)\b/', $class, $x) ? (int) $x[1] : null,
            'played' => $played,
            'home' => [
                'club_id' => (int) $teamIds[1][0],
                'name' => mb_strtoupper($names[0]),
                'badge_url' => $badges[0] ?? null,
            ],
            'away' => [
                'club_id' => (int) $teamIds[1][1],
                'name' => mb_strtoupper($names[1]),
                'badge_url' => $badges[1] ?? null,
            ],
            'kickoff_raw' => $kickoffRaw !== '' ? $kickoffRaw : null,
            'venue' => $venue !== '' ? $venue : null,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
        ];
    }

    private function score(Crawler $node, string $selector): ?int
    {
        $el = $node->filter($selector);
        if (! $el->count()) {
            return null;
        }

        $text = trim($el->text(''));

        return preg_match('/^\d+$/', $text) ? (int) $text : null;
    }
}
