<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\TextGenerator;
use App\Services\Modulistica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/** Modulistica XFive: i 15 documenti analizzati, i file originali e le domande. Nessuna chiamata di rete. */
class DocumentsTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function fake(?string $reply = null, ?\Throwable $error = null, bool $configured = true): object
    {
        $fake = new class($reply, $error, $configured) implements TextGenerator
        {
            public ?string $system = null;

            public ?string $prompt = null;

            public ?float $temperature = null;

            public int $calls = 0;

            public function __construct(private ?string $reply, private ?\Throwable $error, private bool $configured) {}

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function generate(string $system, string $prompt, int $maxTokens = 2000, ?float $temperature = null): string
            {
                $this->calls++;
                $this->system = $system;
                $this->prompt = $prompt;
                $this->temperature = $temperature;

                return $this->error ? throw $this->error : (string) $this->reply;
            }
        };
        $this->app->instance(TextGenerator::class, $fake);

        return $fake;
    }

    public function test_everything_needs_the_staff_login(): void
    {
        $this->getJson('/api/v1/documents')->assertUnauthorized();
        $this->getJson('/api/v1/documents/tabella-sanzioni/file')->assertUnauthorized();
        $this->postJson('/api/v1/documents/ask', ['question' => 'quanto costa un ritardo'])->assertUnauthorized();
    }

    public function test_the_manifest_is_complete_and_consistent(): void
    {
        $m = app(Modulistica::class)->manifest();
        $slugs = array_column($m['documents'], 'slug');

        $this->assertCount(15, $m['documents'], 'sono i 15 documenti del menu Modulistica di XFive');
        $this->assertSame(range(1, 15), array_column($m['documents'], 'number'));
        $this->assertSame($slugs, array_values(array_unique($slugs)), 'gli identificativi sono unici');

        foreach ($m['documents'] as $d) {
            $this->assertNotNull(app(Modulistica::class)->filePath($d['slug']), "manca il file di {$d['slug']}");
            foreach (['title', 'category', 'when', 'purpose', 'summary', 'source_url'] as $field) {
                $this->assertNotSame('', trim((string) ($d[$field] ?? '')), "{$d['slug']}: {$field} vuoto");
            }
            $this->assertNotEmpty($d['key_points'], "{$d['slug']}: nessun punto chiave");
            $this->assertNotEmpty($d['actions'], "{$d['slug']}: nessuna azione");
        }
        foreach (array_merge(array_column($m['price_list'], 'doc'), ...array_column($m['alerts'], 'docs')) as $slug) {
            $this->assertContains($slug, $slugs, "riferimento a un documento che non esiste: {$slug}");
        }
    }

    public function test_the_overview_has_documents_sanctions_rules_checklists_and_prices(): void
    {
        $this->login();

        $data = $this->getJson('/api/v1/documents')->assertOk()->json('data');

        $this->assertCount(15, $data['documents']);
        $this->assertGreaterThan(1000, $data['documents'][0]['size_bytes']);
        $this->assertSame('tabella-sanzioni', $data['documents'][4]['slug']);
        $this->assertCount(19, $data['sanctions']);
        $this->assertCount(24, $data['technical_rules']);
        $this->assertCount(9, $data['registration_checklist']);
        $this->assertCount(11, $data['match_checklist']);
        $this->assertNotEmpty($data['price_list']);
        $this->assertNotEmpty($data['alerts']);

        $byCode = collect($data['sanctions'])->keyBy('code');
        $this->assertSame(500, $byCode['5']['cents_min'], 'espulsione diretta: 5 €');
        $this->assertSame(0, $byCode['1']['cents_max'], 'le ammonizioni non costano');
        $this->assertSame([10000, 3000], [$byCode['6']['cents_min'], $byCode['6A']['cents_min']], 'rinuncia senza avviso 100 €, con avviso 30 €');
    }

    public function test_the_original_files_are_served_with_their_type(): void
    {
        $this->login();

        $pdf = $this->get('/api/v1/documents/tabella-sanzioni/file')->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', $pdf->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('public', (string) $pdf->headers->get('Cache-Control'), 'riservato allo staff: mai in una cache condivisa');

        $jpeg = $this->get('/api/v1/documents/contratto/file')->assertOk();
        $this->assertSame('image/jpeg', $jpeg->headers->get('Content-Type'));
    }

    public function test_unknown_documents_and_odd_names_are_not_found(): void
    {
        $this->login();

        $this->get('/api/v1/documents/non-esiste/file')->assertNotFound();
        $this->get('/api/v1/documents/..%2F..%2F.env/file')->assertNotFound();
        $this->get('/api/v1/documents/Contratto/file')->assertNotFound();
    }

    public function test_the_ai_answers_from_the_documents_with_a_low_temperature(): void
    {
        $this->login();
        $fake = $this->fake('Un\'espulsione diretta per fallo costa 5 € [Tabella sanzioni].');

        $r = $this->postJson('/api/v1/documents/ask', ['question' => 'Quanto costa un\'espulsione diretta?'])->assertOk()->json('data');

        $this->assertSame('ai', $r['source']);
        $this->assertSame("Un'espulsione diretta per fallo costa 5 € [Tabella sanzioni].", $r['answer']);
        $this->assertSame(config('amir.ai.gemini.model'), $r['model']);
        $this->assertNull($r['note']);
        $this->assertSame('tabella-sanzioni', $r['passages'][0]['doc'], 'si allegano i passaggi, così si può controllare');

        $this->assertSame(1, $fake->calls);
        $this->assertSame(0.2, $fake->temperature, 'per i fatti si chiede una risposta precisa, non creativa');
        $this->assertStringContainsString('SOLO le informazioni dei documenti', $fake->system);
        $this->assertStringContainsString('Espulsione diretta per fallo: 5 €', $fake->system, 'tutta la modulistica è nel testo dato all\'IA');
        $this->assertStringContainsString('Palloni in segreteria', $fake->system);
        $this->assertStringContainsString('Quanto costa un\'espulsione diretta?', $fake->prompt);
    }

    public function test_without_a_key_the_most_relevant_passages_are_returned(): void
    {
        $this->login();
        $fake = $this->fake(configured: false);

        $r = $this->postJson('/api/v1/documents/ask', ['question' => 'Cosa succede se arriviamo in ritardo?'])->assertOk()->json('data');

        $this->assertSame(0, $fake->calls, 'senza chiave non parte nessuna richiesta');
        $this->assertSame('search', $r['source']);
        $this->assertNull($r['answer']);
        $this->assertStringContainsString('GEMINI_API_KEY', $r['note']);
        $this->assertContains('Sanzione 9', array_column($r['passages'], 'label'), 'ritardo di 10-15 minuti: 15 €');
    }

    public function test_when_the_ai_fails_the_passages_are_returned_and_the_reason_is_told(): void
    {
        $this->login();
        $this->fake(error: new RuntimeException('timeout'));

        $r = $this->postJson('/api/v1/documents/ask', ['question' => 'Quanti giocatori servono in campo?'])->assertOk()->json('data');

        $this->assertSame('search', $r['source']);
        $this->assertStringContainsString("L'IA non ha risposto", $r['note']);
        $this->assertStringContainsString('timeout', $r['note']);
        $this->assertStringContainsString('minimo 6 giocatori in campo', implode(' ', array_column($r['passages'], 'text')), 'minimo 6 in campo nel c8');
    }

    public function test_questions_are_validated(): void
    {
        $this->login();
        $fake = $this->fake('x');

        $this->postJson('/api/v1/documents/ask', [])->assertUnprocessable();
        $this->postJson('/api/v1/documents/ask', ['question' => 'ab'])->assertUnprocessable();
        $this->postJson('/api/v1/documents/ask', ['question' => str_repeat('x', 501)])->assertUnprocessable();
        $this->assertSame(0, $fake->calls);
    }

    public function test_search_finds_the_right_passages_for_common_questions(): void
    {
        $search = fn (string $q) => array_map(fn ($p) => "{$p['doc']}|{$p['label']}", app(Modulistica::class)->search($q, 4));

        $this->assertContains('tabella-sanzioni|Sanzione 11', $search('persone non tesserate in panchina'));
        $this->assertStringStartsWith('convenzione-visite-mediche|', $search('quanto costa la visita medica agonistica')[0]);
        $this->assertContains('contratto|Listino', $search('costo contratto di due anni'));
        $this->assertContains('note-tecniche-2026-27|Regola 2026/27 b', $search('chi ritira i palloni in segreteria'));
        $this->assertContains('tabella-sanzioni|Sanzione 6', $search('cosa rischiamo se non ci presentiamo alla partita'), 'sinonimi: presentarsi = assenza');
        $this->assertContains('tabella-sanzioni|Sanzione 5', $search('quanto costa un\'espulsione'));
        $this->assertSame([], app(Modulistica::class)->search('la di per'), 'solo parole troppo comuni: nessun risultato');
    }
}
