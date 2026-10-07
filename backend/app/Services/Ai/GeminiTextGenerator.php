<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Scrive testi con Gemini (Google AI Studio, piano gratuito) chiamando l'API REST `generateContent`.
 * La chiave sta in GEMINI_API_KEY (file .env del backend): senza chiave non parte nessuna
 * chiamata e chi usa il servizio ripiega sui modelli di testo.
 */
final class GeminiTextGenerator implements TextGenerator
{
    public function isConfigured(): bool
    {
        return trim((string) config('amir.ai.gemini.api_key')) !== '';
    }

    /**
     * I modelli grandi «ragionano» prima di scrivere e quei token contano nel limite di risposta: con 200 token
     * il testo arrivava vuoto (MAX_TOKENS). Il margine è solo un tetto: la lunghezza la decide la richiesta.
     */
    public const THINKING_HEADROOM = 1500;

    /** Codice dell'eccezione quando Google non si raggiunge proprio (nessuna risposta HTTP). */
    private const UNREACHABLE = 599;

    /** Errori passeggeri: il piano gratuito ha pochi tentativi al minuto per modello (5) e i modelli nuovi sono spesso sovraccarichi. */
    private const BUSY = [429, 500, 502, 503, 504, self::UNREACHABLE];

    public function generate(string $system, string $prompt, int $maxTokens = 2000, ?float $temperature = null): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Chiave Gemini non configurata (GEMINI_API_KEY).');
        }

        // se il modello principale è saturo si prova il secondo (ha una quota a parte); gli altri errori (chiave, richiesta) non si ritentano
        $models = array_values(array_unique(array_filter([(string) config('amir.ai.gemini.model'), (string) config('amir.ai.gemini.fallback_model')])));
        $first = null;
        foreach ($models as $model) {
            try {
                return $this->generateWith($model, $system, $prompt, $maxTokens, $temperature);
            } catch (RuntimeException $e) {
                if (! in_array($e->getCode(), self::BUSY, true)) {
                    throw $e;
                }
                $first ??= $e; // all'utente arriva il motivo del modello principale
            }
        }

        throw $first ?? new RuntimeException('Nessun modello Gemini configurato (GEMINI_MODEL).');
    }

    private function generateWith(string $model, string $system, string $prompt, int $maxTokens, ?float $temperature): string
    {
        $thinking = (string) config('amir.ai.gemini.thinking');
        $response = $this->send($model, $system, $prompt, $maxTokens, $temperature, $thinking !== '' ? $thinking : null);

        // alcuni modelli non accettano il livello di ragionamento: si riprova senza
        if ($response->status() === 400 && $thinking !== '' && str_contains(strtolower((string) $response->body()), 'thinking')) {
            $response = $this->send($model, $system, $prompt, $maxTokens, $temperature, null);
        }

        if ($response->failed()) {
            $status = $response->status();
            $detail = (string) ($response->json('error.message') ?? $response->reason());

            throw new RuntimeException(match ($status) {
                429 => "Gemini ({$model}): limite di richieste del piano gratuito raggiunto, riprova fra qualche secondo.",
                503 => "Gemini ({$model}) è sovraccarico in questo momento, riprova fra poco.",
                default => "Gemini non ha risposto (HTTP {$status}): {$detail}",
            }, $status);
        }

        $block = $response->json('promptFeedback.blockReason');
        if ($block) {
            throw new RuntimeException("Gemini ha bloccato la richiesta ({$block}).");
        }

        // la risposta è una lista di parti: si tiene solo il testo, non i "pensieri" del modello
        $text = '';
        foreach ((array) $response->json('candidates.0.content.parts', []) as $part) {
            if (empty($part['thought']) && isset($part['text'])) {
                $text .= $part['text'];
            }
        }

        $text = trim($text);
        if ($text === '') {
            $why = (string) ($response->json('candidates.0.finishReason') ?? 'risposta vuota');
            throw new RuntimeException("Gemini non ha prodotto testo ({$why}).");
        }

        return $text;
    }

    private function send(string $model, string $system, string $prompt, int $maxTokens, ?float $temperature, ?string $thinking): Response
    {
        $config = ['maxOutputTokens' => $maxTokens + self::THINKING_HEADROOM, 'temperature' => $temperature ?? 0.9];
        if ($thinking !== null) {
            $config['thinkingConfig'] = ['thinkingLevel' => $thinking];
        }

        try {
            return Http::baseUrl(rtrim((string) config('amir.ai.gemini.base_url'), '/'))
                ->withHeaders(['x-goog-api-key' => (string) config('amir.ai.gemini.api_key')])
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('amir.ai.gemini.timeout'))
                ->retry(1, 1500, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post("/v1beta/models/{$model}:generateContent", [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                    'generationConfig' => $config,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Gemini ({$model}) non raggiungibile: ".mb_strimwidth($e->getMessage(), 0, 120, '…'), self::UNREACHABLE, $e);
        }
    }
}
