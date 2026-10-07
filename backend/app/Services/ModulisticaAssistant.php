<?php

namespace App\Services;

use App\Services\Ai\TextGenerator;
use RuntimeException;

/**
 * Risponde alle domande sulla modulistica XFive. Con la chiave Gemini scrive una risposta usando
 * solo il testo dei documenti; senza chiave (o se l'IA non risponde) restituisce i passaggi dei
 * documenti che c'entrano di più. In ogni caso i passaggi sono allegati, così si può controllare.
 */
class ModulisticaAssistant
{
    private const SYSTEM = <<<'TXT'
Sei la segreteria di AMIR COSTRUZIONI, una squadra amatoriale di calcio a 8 iscritta ai tornei XFive di Alessandria. Rispondi alle domande dello staff sulla modulistica XFive.

Regole:
- Rispondi in italiano, in modo breve e concreto (al massimo 8 righe), con importi e scadenze esatti.
- Usa SOLO le informazioni dei documenti qui sotto. Non inventare importi, regole o scadenze.
- Se la risposta non c'è o non è chiara, dillo e consiglia di chiedere a XFive (WhatsApp 338 643 0353).
- Indica fra parentesi quadre il documento da cui prendi l'informazione, per esempio [Tabella sanzioni].
- Se due documenti dicono cose diverse, segnalalo e di' quale vale.
- La nostra squadra gioca a calcio a 8.
TXT;

    public function __construct(private readonly Modulistica $modulistica, private readonly TextGenerator $ai) {}

    /**
     * @return array{answer: string|null, source: 'ai'|'search', model: string|null, note: string|null, passages: list<array<string, string>>}
     */
    public function ask(string $question): array
    {
        $question = trim($question);
        $passages = $this->modulistica->search($question);

        if (! $this->ai->isConfigured()) {
            return $this->reply(null, 'search', null, "Per avere una risposta scritta dall'IA aggiungi GEMINI_API_KEY nel file .env del backend: intanto ecco i passaggi dei documenti che c'entrano di più.", $passages);
        }

        try {
            $answer = $this->ai->generate(
                self::SYSTEM."\n\nDOCUMENTI:\n".$this->modulistica->knowledge(),
                "Domanda dello staff: {$question}",
                900,
                0.2,
            );

            return $this->reply($answer, 'ai', (string) config('amir.ai.gemini.model'), null, $passages);
        } catch (RuntimeException $e) {
            return $this->reply(null, 'search', null, "L'IA non ha risposto, ecco i passaggi dei documenti che c'entrano di più. ".mb_strimwidth($e->getMessage(), 0, 200, '…'), $passages);
        }
    }

    /** @param list<array<string, string>> $passages */
    private function reply(?string $answer, string $source, ?string $model, ?string $note, array $passages): array
    {
        return ['answer' => $answer, 'source' => $source, 'model' => $model, 'note' => $note, 'passages' => $passages];
    }
}
