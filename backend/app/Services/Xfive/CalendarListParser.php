<?php

namespace App\Services\Xfive;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Legge l'elenco delle partite che XFive carica con league.php op=22 (la pagina «Calendario» del sito).
 * Serve per le coppe a tabellone (Coppa di Lega, Coppa di Categoria...): il loro calendario stampabile è un disegno del
 * tabellone senza gli id delle partite, e l'archivio le scartava. Qui ogni partita è un
 * <div class="match-element"> con il nome della fase («COPPA DI LEGA - Ottavo 5»), il link /it/match/{id}/{slug}/, il
 * campo e la data senza anno («Gio 09 APR 20:00»), le due squadre con lo stemma e i due punteggi.
 *
 * Restituisce la stessa forma di PrintableCalendarParser::parse, tranne gli id dei club: qui non ci sono (club_id null),
 * chi importa li ricava dal nome della squadra.
 */
final class CalendarListParser
{
    /**
     * @return array<int, array{
     *   xfive_match_id:int, round_id:null, round:null, round_label:string,
     *   tournament_id:?int, played:bool,
     *   home:array{club_id:null,name:string,badge_url:?string},
     *   away:array{club_id:null,name:string,badge_url:?string},
     *   kickoff_raw:?string, venue:?string, home_score:?int, away_score:?int
     * }>
     */
    public function parse(string $html, ?int $tournamentId = null): array
    {
        $fixtures = [];
        $seen = [];
        (new Crawler($html))->filter('.match-element')->each(function (Crawler $node) use (&$fixtures, &$seen, $tournamentId) {
            $fixture = $this->parseNode($node, $tournamentId);
            if ($fixture !== null && ! isset($seen[$fixture['xfive_match_id']])) {
                $seen[$fixture['xfive_match_id']] = true;
                $fixtures[] = $fixture;
            }
        });

        return $fixtures;
    }

    private function parseNode(Crawler $node, ?int $tournamentId): ?array
    {
        $link = $node->filter('a[href*="/it/match/"]');
        if (! $link->count() || ! preg_match('~/it/match/(\d+)/~', (string) $link->first()->attr('href'), $m)) {
            return null;
        }

        $names = $node->filter('.participant-name')->each(fn (Crawler $n) => trim($n->text('')));
        $badges = $node->filter('.participant-single-row img')->each(fn (Crawler $n) => $n->attr('src'));
        if (count($names) < 2 || $names[0] === '' || $names[1] === '') {
            return null;
        }

        // «COPPA DI LEGA - Ottavo 5»: la fase è quel che segue il nome del torneo
        $title = trim((string) preg_replace('/\s+/', ' ', $node->filter('.match-name')->count() ? $node->filter('.match-name')->text('') : ''));
        $label = str_contains($title, ' - ') ? trim(substr($title, strpos($title, ' - ') + 3)) : $title;

        // «100GRIGIO - CAMPO 4<br /> Gio 09 APR 20:00»: campo e data su due righe
        $header = $node->filter('.match-header');
        $venue = null;
        $kickoff = null;
        if ($header->count()) {
            $parts = preg_split('~<br\s*/?>~i', (string) $header->html('')) ?: [];
            $clean = array_values(array_filter(
                array_map(fn (string $p) => trim((string) preg_replace('/\s+/', ' ', strip_tags($p))), $parts),
                fn (string $p) => $p !== '',
            ));
            if (count($clean) >= 2) {
                [$venue, $kickoff] = [$clean[0], $clean[count($clean) - 1]];
            } elseif (count($clean) === 1) {
                $kickoff = $clean[0];
            }
        }

        $scores = $node->filter('.score')->each(fn (Crawler $n) => trim($n->text('')));
        $homeScore = isset($scores[0]) && preg_match('/^\d+$/', $scores[0]) ? (int) $scores[0] : null;
        $awayScore = isset($scores[1]) && preg_match('/^\d+$/', $scores[1]) ? (int) $scores[1] : null;
        if ($homeScore === null || $awayScore === null) {
            $homeScore = $awayScore = null;
        }

        return [
            'xfive_match_id' => (int) $m[1],
            'round_id' => null,
            'round' => null,
            'round_label' => $label !== '' ? $label : 'Partita',
            'tournament_id' => $tournamentId,
            'played' => $homeScore !== null,
            'home' => ['club_id' => null, 'name' => mb_strtoupper($names[0]), 'badge_url' => $badges[0] ?? null],
            'away' => ['club_id' => null, 'name' => mb_strtoupper($names[1]), 'badge_url' => $badges[1] ?? null],
            'kickoff_raw' => $kickoff,
            'venue' => $venue,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
        ];
    }
}
