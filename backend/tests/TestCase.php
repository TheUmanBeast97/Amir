<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /** HTML del calendario del torneo 187 da servire al posto della fixture (null = fixture salvata). */
    protected ?string $cittadellaCalendar = null;

    protected function fixture(string $name): string
    {
        return file_get_contents(base_path('tests/Fixtures/xfive/'.$name));
    }

    /**
     * Simula le pagine pubbliche di XFive con le fixture salvate in
     * tests/Fixtures/xfive: nei test non parte nessuna richiesta vera.
     */
    protected function fakeXfive(): void
    {
        config(['amir.xfive.throttle_ms' => 0]);

        Http::fake([
            '*/system/include/ajax/public/team.php' => function ($request) {
                $file = match ((string) ($request['sid'] ?? '')) {
                    '8' => 'club_history_2026.json',
                    '7' => 'club_history_2025.json',
                    default => null,
                };

                return Http::response(
                    $file ? $this->fixture($file) : json_encode(['errors' => false, 'html' => '']),
                    200,
                    ['Content-Type' => 'application/json'],
                );
            },
            '*/t-printable.php*' => function ($request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response(match ((string) ($query['t'] ?? '')) {
                    '187' => $this->cittadellaCalendar ?? $this->fixture('calendar_cittadella_2026.html'),
                    '144' => $this->fixture('calendar_history_trimmed.html'),
                    default => '<html><body></body></html>',
                });
            },
        ]);
    }
}
