<?php

namespace Tests\Unit;

use App\Services\Xfive\Archive\Limiter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Il freno dello scarico da XFive: pause, attese dopo gli errori del sito, stop dopo troppi errori. */
class LimiterTest extends TestCase
{
    /** @var array<int, float> */
    private array $slept = [];

    private function limiter(float $gap = 1.0, ?array $window = null, int $hour = 12): Limiter
    {
        $this->slept = [];

        return new Limiter(
            gap: $gap, slowAfter: 4.0, cooldown: 60.0, window: $window,
            sleep: function (float $s) { $this->slept[] = $s; },
            hour: fn () => $hour,
        );
    }

    private function notOurs(): \Closure
    {
        return fn (\Throwable $e) => str_contains($e->getMessage(), '404');
    }

    public function test_it_leaves_a_pause_between_requests_and_none_before_the_first(): void
    {
        $l = $this->limiter(gap: 1.0);
        $l->run(fn () => 'a', $this->notOurs());
        $l->run(fn () => 'b', $this->notOurs());

        $this->assertCount(1, $this->slept, 'una sola pausa, fra la prima e la seconda');
        $this->assertGreaterThan(0.9, $this->slept[0]);
        $this->assertSame(2, $l->requests);
    }

    public function test_a_site_error_waits_a_minute_then_doubles_and_the_request_is_retried(): void
    {
        $l = $this->limiter();
        $calls = 0;
        $result = $l->run(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw new RuntimeException('XFive GET /x fallita (HTTP 503)');
            }

            return 'ok';
        }, $this->notOurs());

        $this->assertSame('ok', $result);
        $this->assertSame(3, $calls);
        $this->assertContains(60.0, $this->slept);
        $this->assertContains(120.0, $this->slept);
    }

    public function test_after_five_site_errors_in_a_row_it_gives_up(): void
    {
        $l = $this->limiter();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mi fermo');

        $l->run(fn () => throw new RuntimeException('XFive GET /x fallita (HTTP 500)'), $this->notOurs());
    }

    public function test_our_own_errors_are_passed_through_without_waiting(): void
    {
        $l = $this->limiter();
        try {
            $l->run(fn () => throw new RuntimeException('XFive GET /x fallita (HTTP 404)'), $this->notOurs());
            $this->fail('doveva rilanciare');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('404', $e->getMessage());
        }
        $this->assertSame([], $this->slept);

        // e non contano come errori del sito: dopo, una risposta buona passa subito
        $this->assertSame('ok', $l->run(fn () => 'ok', $this->notOurs()));
    }

    public function test_slow_answers_double_the_pause(): void
    {
        $l = $this->limiter(gap: 1.0);
        // il tempo misurato è reale (su Windows può risultare zero): con una soglia negativa ogni risposta conta come lenta
        $slow = new Limiter(gap: 1.0, slowAfter: -1.0, sleep: function (float $s) { $this->slept[] = $s; });
        for ($i = 0; $i < 5; $i++) {
            $slow->run(fn () => 'x', $this->notOurs());
        }

        $this->assertSame(2.0, $slow->gap());
        $this->assertSame(1, $slow->slowdowns);
        $this->assertSame(1.0, $l->gap(), 'quello normale non cambia');
    }

    public function test_outside_the_allowed_hours_it_waits_half_an_hour_at_a_time(): void
    {
        $l = $this->limiter(window: [23, 6], hour: 14);
        $l->run(fn () => 'x', $this->notOurs());

        $this->assertNotEmpty($this->slept);
        $this->assertSame(1800.0, $this->slept[0]);

        $night = $this->limiter(window: [23, 6], hour: 2);
        $night->run(fn () => 'x', $this->notOurs());
        $this->assertSame([], $this->slept, 'di notte si parte subito');
    }
}
