<?php

namespace App\Services;

use App\Models\Player;
use App\Models\TeamEvent;
use App\Support\Present;

/** Prepara il testo del sollecito per WhatsApp; l'invio lo fa sempre una persona. */
final class ReminderBuilder
{
    /** @return array{text:string, wa_link:string, missing: array<int, array<string,mixed>>} */
    public function forEvent(TeamEvent $event): array
    {
        $answered = $event->responses()->whereNotNull('rsvp')->pluck('player_id');

        $missing = Player::where('team_id', $event->team_id)
            ->where('is_active', true)
            ->whereNotIn('id', $answered)
            ->orderBy('last_name')
            ->get();

        $when = $event->starts_at
            ? $event->starts_at->locale('it')->translatedFormat('l j F \o\r\e H:i')
            : 'data da definire';

        $lines = ["🔔 {$event->title}", "📅 {$when}"];
        if ($event->venue) {
            $lines[] = "📍 {$event->venue}";
        }
        $lines[] = '';

        if ($missing->isEmpty()) {
            $lines[] = 'Tutti hanno risposto, grazie! 🙌';
        } else {
            $lines[] = 'Manca ancora la risposta di: '.$missing->map(fn (Player $p) => $p->full_name)->implode(', ').'.';
            $lines[] = 'Aprite il vostro link personale e scegliete Ci sono / Forse / Non ci sono. Grazie! 🙏';
        }

        $text = implode("\n", $lines);

        return [
            'text' => $text,
            'wa_link' => 'https://wa.me/?text='.rawurlencode($text),
            'missing' => $missing->map(fn (Player $p) => Present::publicPlayer($p))->values()->all(),
        ];
    }
}
