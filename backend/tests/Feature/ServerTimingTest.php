<?php

namespace Tests\Feature;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ogni risposta dichiara quanto ha richiesto il server e quanto il database: serve a capire dove si perde il tempo. */
class ServerTimingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_answer_carries_the_server_and_database_time(): void
    {
        Team::create(['name' => 'AMIR COSTRUZIONI', 'short_name' => 'AMIR', 'xfive_club_id' => 159, 'is_own' => true, 'format' => 8]);

        $header = (string) $this->getJson('/api/v1/public/roster')->assertOk()->headers->get('Server-Timing');

        $this->assertMatchesRegularExpression('/^app;dur=\d+, db;dur=\d+;desc="\d+ query"$/', $header);
        $this->assertStringNotContainsString('select', strtolower($header), 'solo durate e conteggi, mai testo delle interrogazioni');
    }

    public function test_errors_and_refusals_carry_it_too(): void
    {
        $this->getJson('/api/v1/players')->assertUnauthorized()->assertHeader('Server-Timing');
    }
}
