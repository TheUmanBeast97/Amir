<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventResponse;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamEvent;
use App\Services\ReminderBuilder;
use App\Support\Present;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'type' => ['nullable', Rule::in(['match', 'training', 'social'])],
        ]);

        $own = Team::ownOrFail();
        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : now()->subDays(30);

        $events = TeamEvent::where('team_id', $own->id)
            ->where('starts_at', '>=', $from)
            ->when($request->filled('to'), fn ($q) => $q->where('starts_at', '<=', Carbon::parse($request->query('to'))->endOfDay()))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->query('type')))
            ->orderBy('starts_at')
            ->get();

        return $this->ok($events->map(fn (TeamEvent $e) => Present::event($e))->values()->all());
    }

    /** Le partite arrivano da XFive: qui si creano solo allenamenti e momenti sociali. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['training', 'social'])],
            'title' => ['required', 'string', 'max:120'],
            'starts_at' => ['required', 'date'],
            'venue' => ['nullable', 'string', 'max:160'],
        ]);

        $event = TeamEvent::create($data + ['team_id' => Team::ownOrFail()->id]);

        return $this->ok(Present::event($event), 201);
    }

    public function update(Request $request, TeamEvent $event): JsonResponse
    {
        $this->denyForMatchEvent($event);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'starts_at' => ['sometimes', 'date'],
            'venue' => ['nullable', 'string', 'max:160'],
        ]);

        $event->update($data);

        return $this->ok(Present::event($event->refresh()));
    }

    public function destroy(TeamEvent $event): JsonResponse
    {
        $this->denyForMatchEvent($event);
        $event->delete();

        return $this->ok(new \stdClass);
    }

    public function responses(TeamEvent $event): JsonResponse
    {
        return $this->ok(Present::responsesFor($event));
    }

    /** L'admin può rispondere o segnare la presenza al posto di un giocatore. */
    public function setResponse(Request $request, TeamEvent $event, Player $player): JsonResponse
    {
        abort_unless($player->team_id === $event->team_id, 404);

        $data = $request->validate([
            'rsvp' => ['sometimes', 'nullable', Rule::in(['yes', 'no', 'maybe'])],
            'attended' => ['sometimes', 'nullable', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $response = EventResponse::firstOrNew(['event_id' => $event->id, 'player_id' => $player->id]);

        if (array_key_exists('rsvp', $data)) {
            $response->rsvp = $data['rsvp'];
            $response->responded_at = $data['rsvp'] ? now() : null;
        }
        if (array_key_exists('attended', $data)) {
            $response->attended = $data['attended'];
        }
        if (array_key_exists('note', $data)) {
            $response->note = $data['note'];
        }

        $response->save();

        return $this->ok(Present::response($response->load('player')));
    }

    public function remind(TeamEvent $event, ReminderBuilder $reminders): JsonResponse
    {
        return $this->ok($reminders->forEvent($event));
    }

    private function denyForMatchEvent(TeamEvent $event): void
    {
        if ($event->type === 'match') {
            throw ValidationException::withMessages([
                'event' => ['Gli eventi partita si aggiornano da XFive e non si modificano né si eliminano a mano.'],
            ]);
        }
    }
}
