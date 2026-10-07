<?php

namespace App\Services\Xfive;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Legge le classifiche statistiche di un torneo (risposta "html" di
 * league.php op=19): una tabella a sinistra con posizione, nome abbreviato
 * ("Rossi M."), squadra e foto; una a destra con i valori (G, Pt, A/ESP).
 * Le righe delle due tabelle sono allineate per posizione.
 */
final class StatsTableParser
{
    /**
     * @return array{columns: array<int,string>, rows: array<int, array{position:int, name:string, team:?string, photo:?string, values:array<int,int>}>}
     */
    public function parse(string $html): array
    {
        if (trim($html) === '') {
            return ['columns' => [], 'rows' => []];
        }

        $crawler = new Crawler(mb_check_encoding($html, 'UTF-8') ? $html : mb_convert_encoding($html, 'UTF-8', 'Windows-1252'));

        $columns = $crawler->filter('.right-table .tables-header .col-data')->each(
            fn (Crawler $n) => (string) ($n->attr('title') ?: trim($n->text('')))
        );

        $left = $crawler->filter('.left-table .tables-body.tables-row')->each(function (Crawler $row) {
            $name = $row->filter('.participant-name');
            $team = $name->filter('small');
            $photo = $row->filter('img');

            // il nome è il primo nodo di testo: dentro lo stesso blocco c'è anche <small> con la squadra
            $label = '';
            if ($name->count()) {
                $first = $name->getNode(0)->firstChild;
                $label = $first ? trim($first->textContent) : '';
            }

            return [
                'position' => (int) $row->filter('.col-pos')->text('0'),
                'name' => $label,
                'team' => $team->count() ? trim($team->text('')) : null,
                'photo' => $photo->count() ? $photo->attr('src') : null,
            ];
        });

        $right = $crawler->filter('.right-table .tables-body.tables-row')->each(
            fn (Crawler $row) => $row->filter('.col-data')->each(fn (Crawler $c) => (int) trim($c->text('0')))
        );

        $rows = [];
        foreach ($left as $i => $l) {
            if ($l['name'] === '') {
                continue;
            }
            $rows[] = $l + ['values' => $right[$i] ?? []];
        }

        return ['columns' => $columns, 'rows' => $rows];
    }
}
