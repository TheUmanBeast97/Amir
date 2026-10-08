<?php

namespace Tests\Feature;

use App\Services\Xfive\Admin\AdminRosterParser;
use PHPUnit\Framework\TestCase;

/** La pagina «Rosa» dell'area amministrazione di XFive, letta da un esempio sintetico con la struttura vera. */
class AdminRosterParserTest extends TestCase
{
    private function page(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/xfive/admin-team.html');
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        $rows = [];
        foreach ((new AdminRosterParser)->parse($this->page()) as $row) {
            $rows[$row['admin_id']] = $row;
        }

        return $rows;
    }

    public function test_every_player_row_is_read(): void
    {
        $this->assertSame([9001, 9002, 9003, 9004, 9005], array_keys($this->rows()));
    }

    public function test_a_squad_list_player_with_an_expired_certificate(): void
    {
        $r = $this->rows()[9001];

        $this->assertSame('Rossi Mario', $r['name']);
        $this->assertSame('1995-01-14', $r['birth_date']);
        $this->assertSame('portiere', $r['role']);
        $this->assertSame('2024-11-27', $r['certificate_expires_on']);
        $this->assertSame('https://cdn.enjore.com/wl/xfivesport_it/img/player/q/1001-aaaaaaaaaa.jpg', $r['photo_url']);
        $this->assertSame(1, $r['documents']);
        $this->assertSame([
            'status' => 'approved',
            'title' => 'Tesserato 2026/2027',
            'season' => '2026/2027',
            'date' => '2026-10-07',
            'type' => 'SQUAD LIST (riservato organizzazione)',
            'is_squad_list' => true,
            'fee' => 11.0,
        ], $r['membership']);
    }

    public function test_a_regular_championship_player_without_certificate_or_role(): void
    {
        $r = $this->rows()[9002];

        $this->assertNull($r['role']);
        $this->assertNull($r['certificate_expires_on']);
        $this->assertSame(0, $r['documents']);
        $this->assertSame('approved', $r['membership']['status']);
        $this->assertSame('CAMPIONATI 2026/27', $r['membership']['type']);
        $this->assertFalse($r['membership']['is_squad_list']);
        $this->assertSame(15.0, $r['membership']['fee']);
    }

    public function test_a_surname_with_two_words_stays_whole(): void
    {
        $r = $this->rows()[9003];

        $this->assertSame('De Luca Paolo', $r['name']);
        $this->assertSame('centrocampista', $r['role']);
        $this->assertSame('2027-03-15', $r['certificate_expires_on']);
        $this->assertTrue($r['membership']['is_squad_list']);
        $this->assertSame('2026-10-08', $r['membership']['date']);
    }

    public function test_a_membership_not_yet_approved_and_a_placeholder_photo(): void
    {
        $r = $this->rows()[9004];

        $this->assertSame('pending', $r['membership']['status']);
        $this->assertSame('In attesa di approvazione', $r['membership']['title']);
        $this->assertNull($r['membership']['date']);
        $this->assertNull($r['photo_url'], 'il segnaposto di XFive non è una foto');
    }

    public function test_a_row_with_almost_nothing_does_not_invent_anything(): void
    {
        $r = $this->rows()[9005];

        $this->assertSame('Gialli Marco', $r['name']);
        $this->assertNull($r['birth_date']);
        $this->assertNull($r['role'], '«Responsabile esterno» non è un ruolo di gioco');
        $this->assertNull($r['photo_url']);
        $this->assertSame('none', $r['membership']['status']);
        $this->assertNull($r['membership']['type']);
        $this->assertFalse($r['membership']['is_squad_list']);
        $this->assertNull($r['membership']['fee']);
    }

    public function test_the_login_state_is_recognised_from_the_exit_link(): void
    {
        $parser = new AdminRosterParser;

        $this->assertTrue($parser->isLoggedIn($this->page()));
        $this->assertFalse($parser->isLoggedIn('<html><body><a onclick="suggestionFeedback(\'login\')">Accedi</a></body></html>'));
    }

    public function test_an_impossible_date_is_dropped_and_a_page_without_the_table_gives_nothing(): void
    {
        $html = str_replace('14/01/1995', '31/02/1995', $this->page());
        $rows = array_column((new AdminRosterParser)->parse($html), null, 'admin_id');

        $this->assertNull($rows[9001]['birth_date']);
        $this->assertSame([], (new AdminRosterParser)->parse('<html><body><p>Manutenzione</p></body></html>'));
    }

    public function test_a_page_in_windows_1252_is_converted(): void
    {
        $html = mb_convert_encoding(str_replace('Rossi Mario', 'Niccolò Ferrè', $this->page()), 'Windows-1252', 'UTF-8');

        $rows = array_column((new AdminRosterParser)->parse($html), null, 'admin_id');

        $this->assertSame('Niccolò Ferrè', $rows[9001]['name']);
    }
}
