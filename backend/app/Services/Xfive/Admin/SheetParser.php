<?php

namespace App\Services\Xfive\Admin;

use Illuminate\Support\Carbon;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Legge le risposte di XFive sulla distinta (manage_tournament/team_schedule.php).
 *
 * - op=1 (elenco partite): ogni partita in cui la distinta si può aprire ha l'azione `setSchedule({partita},{squadra})`.
 * - op=2 (apri la distinta): JSON con `html` (il modulo: un campo `players[]` e un `number{id}` per ogni giocatore) oppure `errors`.
 * - op=3 (salva): JSON con `success` oppure `errors`.
 * Quello che non si riconosce resta vuoto invece di essere inventato.
 */
final class SheetParser
{
    /** L'id della squadra (gtid) per la partita, se la distinta è apribile. */
    public function gtidFor(string $scheduleResponse, int $matchId): ?int
    {
        if (preg_match('/setSchedule\(\s*'.$matchId.'\s*,\s*(\d+)\s*\)/', $scheduleResponse, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{html: ?string, errors: ?string, success: ?string}
     */
    public function envelope(array $json): array
    {
        return [
            'html' => isset($json['html']) && is_string($json['html']) ? $json['html'] : null,
            'errors' => $this->text($json['errors'] ?? null),
            'success' => $this->text($json['success'] ?? null),
        ];
    }

    /**
     * Il modulo della distinta.
     *
     * @return array{
     *   hidden: array<string, string>, note: ?string,
     *   players: array<int, array{xfive_id:int, name:string, birth_date:?string, selected:bool, number:?string}>
     * }
     */
    public function form(string $html): array
    {
        $crawler = new Crawler('<html><body>'.$html.'</body></html>');

        $hidden = [];
        $crawler->filter('input[type="hidden"]')->each(function (Crawler $input) use (&$hidden) {
            $name = (string) $input->attr('name');
            if ($name !== '' && ! str_ends_with($name, '[]')) {
                $hidden[$name] = (string) $input->attr('value');
            }
        });

        $note = null;
        $noteField = $crawler->filter('[name="note"]');
        if ($noteField->count() > 0) {
            $note = $noteField->nodeName() === 'textarea' ? trim($noteField->text('')) : (string) $noteField->attr('value');
        }

        $players = [];
        $crawler->filter('input[name="players[]"]')->each(function (Crawler $check) use (&$players) {
            $id = (int) $check->attr('value');
            if ($id <= 0) {
                return;
            }

            $row = $check->ancestors()->filter('.row')->first();
            $name = $row->count() ? trim($row->filter('.playerInfo .name')->text('')) : '';
            $birth = $row->count() ? trim($row->filter('.playerInfo .birthday')->text('')) : '';
            $number = $row->count() && $row->filter('input.jerseyNumber')->count() ? $row->filter('input.jerseyNumber')->attr('value') : null;

            $players[] = [
                'xfive_id' => $id,
                'name' => $name,
                'birth_date' => $this->date($birth),
                'selected' => $check->attr('checked') !== null,
                'number' => $number !== null && trim($number) !== '' ? trim($number) : null,
            ];
        });

        return ['hidden' => $hidden, 'note' => $note, 'players' => $players];
    }

    /** Gli errori di XFive possono essere un testo, una lista o HTML: si riducono a una frase da mostrare. */
    private function text(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_array($value)) {
            $value = implode(' ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', array_values($value)));
        }

        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $text === '' ? null : mb_substr($text, 0, 300);
    }

    private function date(string $dmy): ?string
    {
        if (! preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $dmy)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('d/m/Y', $dmy)->startOfDay()->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
