<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Team;
use Carbon\CarbonImmutable;

/**
 * Feed iCalendar con le partite UFFICIALI (con data e ora) della stagione in
 * corso. Le partite provvisorie, senza data, non compaiono: quando XFive le
 * pubblica, il telefono le aggiunge da solo alla prossima sincronizzazione.
 */
final class IcsBuilder
{
    private const MATCH_MINUTES = 60;

    public function forTeam(Team $own): string
    {
        $games = Game::with(['home', 'away', 'competition'])
            ->involving($own->id)
            ->whereNotNull('kickoff_at')
            ->whereIn('status', [Game::SCHEDULED, Game::PLAYED, Game::POSTPONED])
            ->whereHas('competition', fn ($q) => $q->where('is_current', true))
            ->orderBy('kickoff_at')
            ->get();

        $stamp = CarbonImmutable::now('UTC')->format('Ymd\THis\Z');

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//AMIR Team Manager//IT',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape($own->name),
            'X-WR-TIMEZONE:Europe/Rome',
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
            'X-PUBLISHED-TTL:PT6H',
        ];

        foreach ($games as $game) {
            $start = CarbonImmutable::instance($game->kickoff_at)->utc();
            $end = $start->addMinutes(self::MATCH_MINUTES);

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = "UID:match-{$game->id}@amir-team-manager";
            $lines[] = "DTSTAMP:{$stamp}";
            $lines[] = 'DTSTART:'.$start->format('Ymd\THis\Z');
            $lines[] = 'DTEND:'.$end->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:'.$this->escape("{$game->home->name} - {$game->away->name}");
            if ($game->venue) {
                $lines[] = 'LOCATION:'.$this->escape($game->venue);
            }
            $lines[] = 'DESCRIPTION:'.$this->escape("{$game->competition->name} - {$game->round_label}");
            $lines[] = 'STATUS:'.($game->status === Game::POSTPONED ? 'TENTATIVE' : 'CONFIRMED');
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(fn (string $l) => $this->fold($l), $lines))."\r\n";
    }

    private function escape(string $text): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    /** RFC 5545: righe lunghe al massimo 75 byte, continuate con uno spazio. */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $parts = [];
        $first = true;
        while ($line !== '') {
            $chunk = mb_strcut($line, 0, $first ? 75 : 74);
            $parts[] = ($first ? '' : ' ').$chunk;
            $line = substr($line, strlen($chunk));
            $first = false;
        }

        return implode("\r\n", $parts);
    }
}
