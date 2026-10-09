<?php

namespace Tests\Unit;

use App\Support\Kickoff;
use PHPUnit\Framework\TestCase;

/** La data di una partita XFive, che nel calendario arriva senza anno: l'anno si ricava dalla stagione. */
class KickoffTest extends TestCase
{
    public function test_kickoff_is_dated_with_the_season(): void
    {
        $this->assertSame('2026-10-12 21:00', Kickoff::parse('lun 12/10 21:00', '2026/2027')?->format('Y-m-d H:i'));
        $this->assertSame('2027-02-03 20:30', Kickoff::parse('mer 03/02 20:30', '2026/2027')?->format('Y-m-d H:i'));
        $this->assertSame('2026-10-12 00:00', Kickoff::parse('12/10', '2026/2027')?->format('Y-m-d H:i'), 'senza ora vale mezzanotte');
        $this->assertNull(Kickoff::parse('da definire', '2026/2027'));
        $this->assertNull(Kickoff::parse(null, '2026/2027'));
    }

    public function test_kickoff_is_in_rome_time_and_rejects_impossible_dates(): void
    {
        $this->assertSame('Europe/Rome', Kickoff::parse('gio 30/06 21:00', '2025/2026')?->timezoneName);
        $this->assertSame('2026-06-30', Kickoff::parse('gio 30/06 21:00', '2025/2026')?->toDateString());
        $this->assertNull(Kickoff::parse('31/02 21:00', '2026/2027'));
        $this->assertNull(Kickoff::parse('12/10 21:00', 'boh'));
    }
}
