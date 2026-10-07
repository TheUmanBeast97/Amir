<?php

namespace Tests\Feature;

use App\Services\Ai\GeminiTextGenerator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Il generatore di testi con Gemini: richiesta, lettura della risposta ed errori. Nessuna chiamata di rete. */
class GeminiGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['amir.ai.gemini.api_key' => 'chiave-di-prova', 'amir.ai.gemini.model' => 'gemini-test', 'amir.ai.gemini.fallback_model' => 'gemini-riserva', 'amir.ai.gemini.thinking' => 'low']);
    }

    private function reply(array $parts, array $extra = []): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => 'STOP'] + $extra]];
    }

    public function test_it_sends_the_key_the_model_and_the_instructions_and_returns_the_text(): void
    {
        Http::fake(['*' => Http::response($this->reply([['text' => 'Forza AMIR! '], ['text' => '#XFive']]))]);

        $text = (new GeminiTextGenerator)->generate('Sei un social media manager.', 'Scrivi una didascalia.', 800);

        $this->assertSame('Forza AMIR! #XFive', $text);
        Http::assertSent(function (Request $r) {
            $body = $r->data();

            return $r->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test:generateContent'
                && $r->header('x-goog-api-key') === ['chiave-di-prova']
                && $body['systemInstruction']['parts'][0]['text'] === 'Sei un social media manager.'
                && $body['contents'][0]['parts'][0]['text'] === 'Scrivi una didascalia.'
                && $body['generationConfig']['maxOutputTokens'] === 800 + GeminiTextGenerator::THINKING_HEADROOM
                && $body['generationConfig']['thinkingConfig']['thinkingLevel'] === 'low';
        });
    }

    public function test_the_temperature_defaults_to_creative_and_can_be_lowered(): void
    {
        Http::fake(['*' => Http::response($this->reply([['text' => 'Ok']]))]);
        $generator = new GeminiTextGenerator;

        $generator->generate('s', 'p');
        $generator->generate('s', 'p', 500, 0.2);

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data()['generationConfig']['temperature'])->values()->all();
        $this->assertSame([0.9, 0.2], $sent, 'per i testi social 0,9; per i fatti si può abbassare');
    }

    public function test_the_models_thoughts_are_not_part_of_the_answer(): void
    {
        Http::fake(['*' => Http::response($this->reply([['text' => 'ragiono su cosa scrivere', 'thought' => true], ['text' => 'Testo vero']]))]);

        $this->assertSame('Testo vero', (new GeminiTextGenerator)->generate('s', 'p'));
    }

    public function test_a_model_that_rejects_the_thinking_level_is_retried_without_it(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['message' => 'Unknown name "thinkingLevel": Cannot find field.']], 400)
            ->push($this->reply([['text' => 'Ok']]))]);

        $this->assertSame('Ok', (new GeminiTextGenerator)->generate('s', 'p'));
        Http::assertSentCount(2);
        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data()['generationConfig'])->values();
        $this->assertArrayHasKey('thinkingConfig', $sent[0]);
        $this->assertArrayNotHasKey('thinkingConfig', $sent[1]);
    }

    public function test_a_busy_main_model_falls_back_to_the_second_one(): void
    {
        Http::fake([
            '*gemini-test:generateContent' => Http::response(['error' => ['message' => 'Quota exceeded']], 429),
            '*gemini-riserva:generateContent' => Http::response($this->reply([['text' => 'Risposta di riserva']])),
        ]);

        $this->assertSame('Risposta di riserva', (new GeminiTextGenerator)->generate('s', 'p'));
        Http::assertSentCount(2);
        $urls = Http::recorded()->map(fn ($pair) => $pair[0]->url())->values()->all();
        $this->assertStringContainsString('gemini-test:generateContent', $urls[0]);
        $this->assertStringContainsString('gemini-riserva:generateContent', $urls[1]);
    }

    public function test_when_every_model_is_busy_the_message_is_clear_and_names_the_main_model(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Quota exceeded for metric … limit: 5 …']], 429)]);

        try {
            (new GeminiTextGenerator)->generate('s', 'p');
            $this->fail('doveva fallire');
        } catch (RuntimeException $e) {
            $this->assertSame(429, $e->getCode());
            $this->assertStringContainsString('gemini-test', $e->getMessage());
            $this->assertStringContainsString('limite di richieste del piano gratuito', $e->getMessage());
            $this->assertStringNotContainsString('metric', $e->getMessage(), 'niente messaggio tecnico di Google');
        }
        Http::assertSentCount(2);
    }

    public function test_an_overloaded_model_says_so(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'high demand']], 503)]);

        try {
            (new GeminiTextGenerator)->generate('s', 'p');
            $this->fail('doveva fallire');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sovraccarico', $e->getMessage());
        }
        Http::assertSentCount(2); // principale e riserva
    }

    public function test_a_wrong_key_or_a_bad_request_is_not_retried_on_the_second_model(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'API key not valid']], 403)]);

        try {
            (new GeminiTextGenerator)->generate('s', 'p');
            $this->fail('doveva fallire');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('API key not valid', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_api_errors_become_a_readable_exception(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'API key not valid. Please pass a valid API key.']], 400)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API key not valid');
        (new GeminiTextGenerator)->generate('s', 'p');
    }

    public function test_a_blocked_prompt_and_an_empty_answer_are_errors(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['promptFeedback' => ['blockReason' => 'SAFETY']])
            ->push(['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => []]]]])]);

        $generator = new GeminiTextGenerator;
        try {
            $generator->generate('s', 'p');
            $this->fail('doveva fallire');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SAFETY', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MAX_TOKENS');
        $generator->generate('s', 'p');
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        config(['amir.ai.gemini.api_key' => null]);
        Http::fake();
        $generator = new GeminiTextGenerator;

        $this->assertFalse($generator->isConfigured());
        try {
            $generator->generate('s', 'p');
            $this->fail('doveva fallire');
        } catch (RuntimeException) {
            Http::assertNothingSent();
        }
    }
}
