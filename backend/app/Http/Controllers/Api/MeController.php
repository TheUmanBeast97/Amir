<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventResponse;
use App\Models\Player;
use App\Models\TeamEvent;
use App\Services\FinanceService;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Area del singolo giocatore: si entra con il link personale, senza password. */
class MeController extends Controller
{
    public function show(string $token, FinanceService $finance): JsonResponse
    {
        $player = $this->player($token);

        $events = TeamEvent::where('team_id', $player->team_id)
            ->where('starts_at', '>=', now()->subHours(3))
            ->orderBy('starts_at')
            ->limit(10)
            ->get();

        return $this->ok([
            'player' => Present::publicPlayer($player),
            'upcoming_events' => $events->map(fn (TeamEvent $e) => Present::event($e, $player->id))->values()->all(),
            'balance' => $finance->playerBalance($player),
        ]);
    }

    public function rsvp(Request $request, string $token, TeamEvent $event): JsonResponse
    {
        $player = $this->player($token);
        abort_unless($event->team_id === $player->team_id, 404);

        $data = $request->validate([
            'rsvp' => ['required', Rule::in(['yes', 'no', 'maybe'])],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        EventResponse::updateOrCreate(
            ['event_id' => $event->id, 'player_id' => $player->id],
            ['rsvp' => $data['rsvp'], 'note' => $data['note'] ?? null, 'responded_at' => now()],
        );

        return $this->ok(Present::event($event->refresh(), $player->id));
    }

    private function player(string $token): Player
    {
        return Player::where('access_token', $token)->where('is_active', true)->firstOrFail();
    }
}
