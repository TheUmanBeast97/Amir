<?php

namespace App\Services\Xfive\Admin;

use DateTimeImmutable;
use DOMText;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Legge la rosa dell'area amministrazione di XFive (manage_tournament.php?tmid={club}&sk=team).
 *
 * La pagina mostra una tabella che il browser riempie con una richiesta a parte (team.php, op=1): la risposta è un JSON con una
 * riga per giocatore, `DT_RowId` ("row_{id}") e sei frammenti HTML, uno per colonna. Questa classe legge quel JSON, e anche la tabella
 * già scritta nella pagina (se un giorno XFive la servisse così).
 *
 * Colonne: foto, «Cognome Nome» con la data di nascita sotto, ruolo, scadenza del certificato medico, stato del tesseramento
 * (con data, tipo, importo), azioni. Quello che non si riconosce resta vuoto invece di essere inventato.
 */
final class AdminRosterParser
{
    private const ROLES = ['portiere', 'difensore', 'centrocampista', 'attaccante', 'dirigente', 'allenatore'];

    /** In una pagina con l'accesso fatto c'è sempre il collegamento per uscire. */
    public function isLoggedIn(string $html): bool
    {
        return str_contains($html, 'logout.php');
    }

    /**
     * @param  string  $payload  il JSON di team.php, oppure l'HTML della pagina con la tabella già scritta
     * @return array<int, array{
     *   admin_id:int, name:string, birth_date:?string, role:?string, certificate_expires_on:?string, photo_url:?string, documents:int,
     *   membership: array{status:string, title:?string, season:?string, date:?string, type:?string, is_squad_list:bool, fee:?float}
     * }>
     */
    public function parse(string $payload): array
    {
        $payload = $this->utf8($payload);

        return str_starts_with(ltrim($payload), '{') ? $this->fromJson($payload) : $this->fromHtml($payload);
    }

    /** @return array<int, array<string, mixed>> */
    private function fromJson(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! is_array($data['data'] ?? null)) {
            return [];
        }

        $rows = [];
        foreach ($data['data'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $cells = [];
            for ($i = 0; $i < 6; $i++) {
                $cells[] = (new Crawler('<table><tbody><tr><td>'.(string) ($item[$i] ?? '').'</td></tr></tbody></table>'))->filter('td');
            }

            if ($row = $this->row((string) ($item['DT_RowId'] ?? ''), $cells)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function fromHtml(string $html): array
    {
        $rows = [];

        (new Crawler($html))->filter('#dt-team tbody tr')->each(function (Crawler $tr) use (&$rows) {
            $tds = $tr->filter('td');
            $cells = [];
            for ($i = 0; $i < 6; $i++) {
                $cells[] = $i < $tds->count() ? $tds->eq($i) : new Crawler;
            }

            if ($row = $this->row((string) $tr->attr('id'), $cells)) {
                $rows[] = $row;
            }
        });

        return $rows;
    }

    /**
     * @param  array<int, Crawler>  $cells  le sei colonne
     * @return array<string, mixed>|null
     */
    private function row(string $rowId, array $cells): ?array
    {
        if (! preg_match('/^row_(\d+)$/', $rowId, $m)) {
            return null; // la riga «nessun risultato» o simili
        }

        $name = $this->name($cells[1]);
        if ($name === '') {
            return null;
        }

        return [
            'admin_id' => (int) $m[1],
            'name' => $name,
            'birth_date' => $this->date($this->firstDate($cells[1]->text(''))),
            'role' => $this->role($cells[2]->text('')),
            'certificate_expires_on' => $this->date($this->firstDate($cells[3]->text(''))),
            'photo_url' => $this->photo($cells[0]),
            'documents' => $this->documents($cells[5]),
            'membership' => $this->membership($cells[4], $cells[4]->filter('.action-recap')),
        ];
    }

    /** «Cognome Nome» è il testo diretto della cella, prima della data di nascita (che sta in un div). */
    private function name(Crawler $cell): string
    {
        $text = '';
        foreach ($cell->count() ? ($cell->getNode(0)?->childNodes ?? []) : [] as $child) {
            if ($child instanceof DOMText) {
                $text .= $child->textContent;
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function firstDate(string $text): ?string
    {
        // nella pagina il nome e la data di nascita sono attaccati («Rossi Mario14/01/1995»): niente confini di parola
        return preg_match('#(?<!\d)(\d{2}/\d{2}/\d{4})(?!\d)#', $text, $m) ? $m[1] : null;
    }

    /** 14/01/1995 diventa 1995-01-14; una data impossibile (31/02) diventa nulla. */
    private function date(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!d/m/Y', $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ? $date->format('Y-m-d')
            : null;
    }

    private function role(string $text): ?string
    {
        $role = mb_strtolower(trim($text));

        return in_array($role, self::ROLES, true) ? $role : null;
    }

    /** La foto vera, non il segnaposto ("ph_...") che XFive mette a chi non l'ha caricata. */
    private function photo(Crawler $cell): ?string
    {
        $link = $cell->filter('a')->count() ? $cell->filter('a')->attr('href') : null;
        $url = $link ?: ($cell->filter('img')->count() ? $cell->filter('img')->attr('src') : null);

        return $url && ! str_contains(basename((string) parse_url($url, PHP_URL_PATH)), 'ph_') ? $url : null;
    }

    /** Quanti documenti ha caricato (il numero nel pallino accanto alla graffetta). */
    private function documents(Crawler $cell): int
    {
        $notify = $cell->filter('.notify');

        return $notify->count() ? (int) preg_replace('/\D/', '', $notify->text('')) : 0;
    }

    /** @return array{status:string, title:?string, season:?string, date:?string, type:?string, is_squad_list:bool, fee:?float} */
    private function membership(Crawler $cell, Crawler $recap): array
    {
        $title = $recap->count() ? trim((string) $recap->attr('title')) : '';
        // l'indicatore è il div interno con la classe «membership-approved» (o simile); il div esterno è «action-recap»
        $marker = $recap->count() && $recap->filter('[class*="membership-"]')->count() ? (string) $recap->filter('[class*="membership-"]')->attr('class') : '';
        $recapText = $recap->count() ? trim($recap->text('')) : '';

        $status = match (true) {
            $recap->count() === 0 => 'none',
            str_contains($marker, 'approved') => 'approved',
            str_contains($marker, 'pending') => 'pending',
            default => 'other',
        };

        // dopo la data viene il tipo: «SQUAD LIST (riservato organizzazione) (11 €)» o «CAMPIONATI 2026/27 (15 €)»
        $type = trim((string) preg_replace('/\s+/u', ' ', str_replace($recapText, '', $cell->text(''))));
        $fee = preg_match('/\((\d+(?:[.,]\d+)?)\s*€\)/u', $type, $f) ? (float) str_replace(',', '.', $f[1]) : null;
        $label = trim((string) preg_replace('/\s*\(\d+(?:[.,]\d+)?\s*€\)\s*$/u', '', $type));

        return [
            'status' => $status,
            'title' => $title !== '' ? $title : null,
            'season' => preg_match('#\b(\d{4}/\d{4})\b#', $title, $s) ? $s[1] : null,
            'date' => $this->date($this->firstDate($recapText)),
            'type' => $label !== '' ? $label : null,
            'is_squad_list' => stripos($label, 'SQUAD LIST') !== false,
            'fee' => $fee,
        ];
    }

    /** Alcune pagine di XFive sono in Windows-1252 pur dichiarando UTF-8. */
    private function utf8(string $html): string
    {
        return mb_check_encoding($html, 'UTF-8') ? $html : mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
    }
}
