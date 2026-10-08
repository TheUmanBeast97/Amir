<?php

namespace Tests\Support;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Gli esempi sintetici della rosa dell'area amministrazione di XFive.
 * La pagina vera non contiene le righe: il browser le chiede a parte (team.php, op=1) e riceve un JSON con una riga per giocatore
 * (`DT_RowId` e sei frammenti HTML, uno per colonna). Qui quel JSON si ricava dalla tabella dell'esempio, così le due forme coincidono.
 */
final class AdminRosterFixtures
{
    public static function html(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/xfive/admin-team.html');
    }

    /** La risposta di team.php, con le stesse righe della tabella di esempio. */
    public static function ajaxJson(): string
    {
        $data = [];

        (new Crawler(self::html()))->filter('#dt-team tbody tr')->each(function (Crawler $tr) use (&$data) {
            $row = ['DT_RowId' => (string) $tr->attr('id')];
            foreach ($tr->filter('td') as $i => $td) {
                $row[$i] = (new Crawler($td))->html();
            }
            $data[] = $row;
        });

        return (string) json_encode(['draw' => 1, 'recordsTotal' => count($data), 'recordsFiltered' => count($data), 'data' => $data], JSON_UNESCAPED_UNICODE);
    }

    /** La pagina «Rosa» come la manda XFive: la tabella c'è ma è vuota, e c'è il collegamento per uscire. */
    public static function shellPage(): string
    {
        return '<html><body><a href="https://www.xfivesport.it/logout.php">Esci</a><table id="dt-team"><thead><tr><th></th></tr></thead><tbody></tbody></table></body></html>';
    }
}
