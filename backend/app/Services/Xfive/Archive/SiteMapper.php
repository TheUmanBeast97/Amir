<?php

namespace App\Services\Xfive\Archive;

use Symfony\Component\DomCrawler\Crawler;

/**
 * La mappa di una pagina di XFive: i link interni raggruppati per forma, le tendine (stagioni, tornei...) con le loro
 * voci e le chiamate interne (/system/include/ajax/...) citate negli script. Serve a progettare lo scarico vero
 * guardando com'è fatto il sito, invece di indovinare.
 */
final class SiteMapper
{
    /**
     * @return array{
     *   title:string,
     *   links:array<string, array{count:int, examples:array<int,string>}>,
     *   selects:array<int, array{name:?string, id:?string, options:array<int, array{value:string, label:string}>}>,
     *   ajax:array<int,string>,
     *   forms:array<int, array{action:?string, method:?string, fields:array<int,string>}>
     * }
     */
    public function map(string $html, string $baseHost): array
    {
        $c = new Crawler($html);

        $groups = [];
        foreach ($c->filter('a[href]') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
                continue;
            }
            $host = parse_url($href, PHP_URL_HOST);
            if ($host && ! str_ends_with((string) $host, $baseHost)) {
                continue;
            }
            $path = (string) (parse_url($href, PHP_URL_PATH) ?? '/');
            $shape = preg_replace('#/\d+(?=/|$)#', '/N', $path) ?? $path;
            $shape = preg_replace('#/[a-z0-9]+(?:-[a-z0-9]+)+(?=/|$)#', '/slug', $shape) ?? $shape;
            $groups[$shape] ??= ['count' => 0, 'examples' => []];
            $groups[$shape]['count']++;
            if (count($groups[$shape]['examples']) < 3 && ! in_array($href, $groups[$shape]['examples'], true)) {
                $groups[$shape]['examples'][] = $href;
            }
        }
        uasort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);

        $selects = [];
        foreach ($c->filter('select') as $s) {
            $sel = new Crawler($s);
            $options = [];
            foreach ($sel->filter('option') as $o) {
                $options[] = ['value' => (string) $o->getAttribute('value'), 'label' => trim((string) $o->textContent)];
            }
            $selects[] = ['name' => $s->getAttribute('name') ?: null, 'id' => $s->getAttribute('id') ?: null, 'options' => array_slice($options, 0, 40)];
        }

        preg_match_all('#/system/include/ajax/[A-Za-z0-9_/.-]+\.php#', $html, $m);
        $ajax = array_values(array_unique($m[0]));
        sort($ajax);

        $forms = [];
        foreach ($c->filter('form') as $f) {
            $form = new Crawler($f);
            $fields = [];
            foreach ($form->filter('input[name], select[name], textarea[name]') as $i) {
                $fields[] = (string) $i->getAttribute('name');
            }
            $forms[] = ['action' => $f->getAttribute('action') ?: null, 'method' => $f->getAttribute('method') ?: null, 'fields' => array_values(array_unique($fields))];
        }

        return [
            'title' => trim($c->filter('title')->count() ? $c->filter('title')->text() : ''),
            'links' => $groups,
            'selects' => $selects,
            'ajax' => $ajax,
            'forms' => $forms,
        ];
    }
}
