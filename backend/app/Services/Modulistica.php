<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * La modulistica XFive (i 15 documenti del menu «Modulistica» dell'area amministrazione).
 *
 * L'analisi in italiano sta in resources/modulistica/manifest.json, gli originali (PDF e immagini)
 * in resources/modulistica/files/. Per aggiornare un documento si sostituisce il file e si
 * corregge la sua voce nel manifest: non c'è nulla da importare nel database.
 */
class Modulistica
{
    /** Parole della domanda che non dicono nulla sull'argomento. Le parole comuni dei documenti non servono qui: pesano poco da sole (vedi search). */
    private const STOPWORDS = [
        'che', 'cosa', 'come', 'quando', 'quanto', 'quanti', 'quante', 'quale', 'quali', 'dove', 'chi', 'per', 'con', 'una', 'uno', 'gli', 'dei', 'del', 'della',
        'delle', 'dal', 'dalla', 'nel', 'nella', 'sul', 'sulla', 'sono', 'essere', 'fare', 'devo', 'deve', 'posso', 'puo', 'non', 'piu', 'anche', 'dopo', 'prima',
    ];

    /**
     * Come lo staff chiama le cose e come le chiamano i documenti: radice della parola cercata => radici da cercare in più.
     * Serve solo alla ricerca di riserva (l'IA capisce da sola).
     */
    private const SYNONYMS = [
        'presen' => ['assenz', 'rinunc', 'disput'], // «non ci presentiamo» = «partita non disputata per assenza»
        'forfai' => ['assenz', 'rinunc', 'disput'],
        'mult' => ['sanzio'],
        'cartel' => ['ammoniz', 'espuls'],
        'ross' => ['espuls'],
        'giall' => ['ammoniz'],
        'prezz' => ['cost'],
        'pagar' => ['cost', 'versam'],
    ];

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        if ($this->manifest === null) {
            $path = resource_path('modulistica/manifest.json');
            if (! is_file($path)) {
                throw new RuntimeException('Manca resources/modulistica/manifest.json.');
            }
            $this->manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }

        return $this->manifest;
    }

    /** Tutto quello che serve alla pagina «Documenti»: l'analisi più la dimensione dei file. */
    public function overview(): array
    {
        $m = $this->manifest();
        $m['documents'] = array_map(function (array $d) {
            $path = $this->filePath($d['slug']);
            $d['size_bytes'] = $path ? (int) filesize($path) : null;

            return $d;
        }, $m['documents']);

        return $m;
    }

    /** @return array<string, mixed>|null */
    public function document(string $slug): ?array
    {
        foreach ($this->manifest()['documents'] as $d) {
            if ($d['slug'] === $slug) {
                return $d;
            }
        }

        return null;
    }

    /** Percorso del file originale; nullo se il documento non esiste o il file manca. */
    public function filePath(string $slug): ?string
    {
        $doc = $this->document($slug);
        if (! $doc) {
            return null;
        }
        $path = resource_path('modulistica/files/'.basename($doc['file']));

        return is_file($path) ? $path : null;
    }

    /**
     * Tutto il contenuto diviso in brevi passaggi, ciascuno con il documento da cui viene:
     * serve sia alla ricerca sia al testo dato all'IA.
     *
     * @return list<array{doc: string, doc_title: string, label: string, text: string}>
     */
    public function passages(): array
    {
        $m = $this->manifest();
        $titles = array_column($m['documents'], 'title', 'slug');
        $out = [];
        $add = function (string $doc, string $label, string $text) use (&$out, $titles) {
            $text = trim($text);
            if ($text !== '') {
                $out[] = ['doc' => $doc, 'doc_title' => $titles[$doc] ?? $doc, 'label' => $label, 'text' => $text];
            }
        };

        foreach ($m['documents'] as $d) {
            $add($d['slug'], 'Sintesi', "{$d['purpose']} {$d['summary']} Quando serve: {$d['when']}");
            foreach ($d['key_points'] as $p) {
                $add($d['slug'], 'Punto chiave', $p);
            }
            foreach ($d['amounts'] as $a) {
                $add($d['slug'], 'Importo', "{$a['label']}: {$a['amount']}");
            }
            foreach ($d['actions'] as $a) {
                $add($d['slug'], 'Cosa fare', $a);
            }
        }
        foreach ($m['sanctions'] as $s) {
            $add('tabella-sanzioni', "Sanzione {$s['code']}", "{$s['title']}: {$s['amount']}".($s['extra'] !== '' ? " {$s['extra']}" : ''));
        }
        foreach ($m['technical_rules'] as $r) {
            $add('note-tecniche-2026-27', "Regola {$r['n']}", "{$r['topic']}. {$r['text']}");
        }
        foreach ($m['registration_checklist'] as $i) {
            $add('checklist-iscrizioni-partita', 'Checklist iscrizione', $i);
        }
        foreach ($m['match_checklist'] as $i) {
            $add('checklist-iscrizioni-partita', 'Checklist partita', $i['text'].($i['tip'] !== '' ? ". {$i['tip']}" : ''));
        }
        foreach ($m['price_list'] as $p) {
            $add($p['doc'], 'Listino', "{$p['group']}: {$p['label']}: {$p['amount']}");
        }
        foreach ($m['alerts'] as $a) {
            $add($a['docs'][0] ?? 'contratto', 'Da sapere', "{$a['title']}. {$a['text']}");
        }

        return $out;
    }

    /**
     * Ricerca per parole nei passaggi (senza IA): i più pertinenti, dal più al meno.
     *
     * @return list<array{doc: string, doc_title: string, label: string, text: string}>
     */
    public function search(string $question, int $limit = 6): array
    {
        $stems = [];
        foreach (preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($question)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (strlen($word) >= 3 && ! in_array($word, self::STOPWORDS, true)) {
                $stems[$this->stem($word)] = true;
            }
        }
        if (! $stems) {
            return [];
        }
        foreach (array_keys($stems) as $stem) {
            foreach (self::SYNONYMS[$stem] ?? [] as $extra) {
                $stems[$extra] = true;
            }
        }

        $passages = $this->passages();
        $haystacks = array_map(fn ($p) => Str::lower(Str::ascii($p['label'].' '.$p['text'].' '.$p['doc_title'])), $passages);

        // BM25 semplificato: una parola rara nei documenti («agonistica») vale più di una che c'è ovunque
        // («giocatori»), e una riga breve e mirata batte un riassunto lungo che nomina tutto.
        $n = count($haystacks);
        $lengths = array_map(fn ($h) => substr_count($h, ' ') + 1, $haystacks);
        $avg = array_sum($lengths) / max(1, $n);
        $idf = [];
        foreach (array_keys($stems) as $stem) {
            $df = count(array_filter($haystacks, fn ($h) => str_contains($h, $stem)));
            if ($df > 0) {
                $idf[$stem] = log(1 + ($n - $df + 0.5) / ($df + 0.5));
            }
        }

        $scored = [];
        foreach ($haystacks as $i => $haystack) {
            $score = 0.0;
            foreach ($idf as $stem => $weight) {
                $hits = substr_count($haystack, $stem);
                if ($hits > 0) {
                    $score += $weight * ($hits * 2.2) / ($hits + 1.2 * (0.25 + 0.75 * $lengths[$i] / $avg));
                }
            }
            if ($score > 0) {
                $scored[] = [$score, $i];
            }
        }
        usort($scored, fn ($a, $b) => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);

        return array_map(fn ($s) => $passages[$s[1]], array_slice($scored, 0, $limit));
    }

    /** Tutto il contenuto come testo unico, da dare all'IA come unica fonte. */
    public function knowledge(): string
    {
        $m = $this->manifest();
        $lines = [];
        foreach ($m['documents'] as $d) {
            $lines[] = "## Documento {$d['number']}: {$d['title']}";
            $lines[] = "Scopo: {$d['purpose']}";
            $lines[] = "Quando serve: {$d['when']}";
            $lines[] = "Sintesi: {$d['summary']}";
            foreach ($d['key_points'] as $p) {
                $lines[] = "- {$p}";
            }
            foreach ($d['amounts'] as $a) {
                $lines[] = "- Importo, {$a['label']}: {$a['amount']}";
            }
            foreach ($d['actions'] as $a) {
                $lines[] = "- Cosa fare: {$a}";
            }
            $lines[] = '';
        }

        $lines[] = '## Tabella sanzioni economiche, voce per voce';
        foreach ($m['sanctions'] as $s) {
            $lines[] = "- {$s['code']}) {$s['title']}: {$s['amount']}".($s['extra'] !== '' ? " {$s['extra']}" : '');
        }
        $lines[] = '';
        $lines[] = '## Note tecniche sul regolamento, articolo per articolo';
        foreach ($m['technical_rules'] as $r) {
            $lines[] = "- {$r['n']}. {$r['topic']}: {$r['text']}";
        }
        $lines[] = '';
        $lines[] = '## Checklist di iscrizione';
        foreach ($m['registration_checklist'] as $i) {
            $lines[] = "- {$i}";
        }
        $lines[] = '## Checklist partita';
        foreach ($m['match_checklist'] as $i) {
            $lines[] = '- '.$i['text'].($i['tip'] !== '' ? " ({$i['tip']})" : '');
        }
        $lines[] = $m['match_checklist_footer'];
        $lines[] = '';
        $lines[] = '## Listino costi';
        foreach ($m['price_list'] as $p) {
            $lines[] = "- {$p['group']}: {$p['label']}: {$p['amount']}";
        }
        $lines[] = '';
        $lines[] = '## Incongruenze e punti da chiarire con XFive';
        foreach ($m['alerts'] as $a) {
            $lines[] = "- {$a['title']}: {$a['text']}";
        }

        return implode("\n", $lines);
    }

    /** Radice approssimata di una parola italiana: «tesserati» e «tesseramento» arrivano alla stessa. */
    private function stem(string $word): string
    {
        if (strlen($word) > 6) {
            return substr($word, 0, 6);
        }

        return strlen($word) >= 5 ? rtrim($word, 'aeio') : $word;
    }
}
