<?php

namespace App\Support;

use App\Models\Competition;
use App\Models\EventResponse;
use App\Models\Game;
use App\Models\Lineup;
use App\Models\Player;
use App\Models\Team;
use App\Models\TeamEvent;
use Illuminate\Support\Str;

/**
 * Trasforma i modelli negli oggetti JSON del contratto API (docs/api-types.ts).
 * I nomi dei campi devono restare identici a quelli dei tipi TypeScript.
 */
final class Present
{
    /** @return array<string, mixed> */
    public static function team(Team $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'short_name' => $t->short_name ?? $t->name,
            // stemma scaricato in locale (stessa origine dell'API, con CORS) se c'è, altrimenti quello di XFive
            'badge_url' => $t->badge_path ? url("/api/v1/public/badges/{$t->id}") : $t->badge_url,
            'is_own' => (bool) $t->is_own,
            'kit1_color' => $t->kit1_color,
            'kit2_color' => $t->kit2_color,
            'format' => $t->format !== null ? (int) $t->format : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function competition(Competition $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'season' => $c->season,
            'kind' => $c->kind,
            'format' => (int) $c->format,
            'is_current' => (bool) $c->is_current,
            'starts_on' => $c->starts_on?->toDateString(),
            'ends_on' => $c->ends_on?->toDateString(),
            'total_rounds' => $c->total_rounds,
            'scheduled_rounds' => $c->scheduledRounds(),
            'xfive_url' => $c->xfive_url,
        ];
    }

    /** La partita deve avere caricate le relazioni competition, home, away (e event). */
    public static function match(Game $g, ?int $ownId): array
    {
        $isOwn = $ownId !== null && ($g->home_team_id === $ownId || $g->away_team_id === $ownId);

        return [
            'id' => $g->id,
            'competition' => [
                'id' => $g->competition->id,
                'name' => $g->competition->name,
                'kind' => $g->competition->kind,
                'season' => $g->competition->season,
                'format' => (int) $g->competition->format,
            ],
            'round' => $g->round,
            'round_label' => $g->round_label ?? 'Partita',
            'home_team' => self::team($g->home),
            'away_team' => self::team($g->away),
            'kickoff_at' => $g->kickoff_at?->toIso8601String(),
            'venue' => $g->venue,
            'home_score' => $g->home_score,
            'away_score' => $g->away_score,
            'status' => $g->status,
            'is_provisional' => $g->isProvisional(),
            'is_own_match' => $isOwn,
            'is_friendly' => $g->competition->kind === Competition::KIND_FRIENDLY,
            'our_kit' => $g->our_kit,
            'result' => $isOwn ? $g->resultFor($ownId) : null,
            'event_id' => $g->relationLoaded('event') ? $g->event?->id : null,
            'xfive_url' => self::matchUrl($g),
        ];
    }

    public static function matchUrl(Game $g): ?string
    {
        if (! $g->xfive_match_id) {
            return null;
        }

        $slug = Str::slug($g->home->name).'-'.Str::slug($g->away->name);

        return rtrim((string) config('amir.xfive.base_url'), '/')."/it/match/{$g->xfive_match_id}/{$slug}/";
    }

    /**
     * @param  array<string, mixed>  $row  riga di StandingsCalculator
     * @return array<string, mixed>
     */
    public static function standingRow(array $row): array
    {
        $row['team'] = self::team($row['team']);

        return $row;
    }

    /** @return array<string, mixed> */
    public static function publicPlayer(Player $p): array
    {
        return [
            'id' => $p->id,
            'full_name' => $p->full_name,
            'nickname' => $p->nickname,
            'shirt_number' => $p->shirt_number,
            // il numero dipende dalla divisa: rossa o bianca (vedi match.our_kit)
            'shirt_number_red' => $p->shirt_number_red,
            'shirt_number_white' => $p->shirt_number_white,
            'role' => $p->role,
            'photo_url' => $p->photo_path ? url("/api/v1/public/players/{$p->id}/photo") : $p->photo_url,
            // la sagoma senza sfondo per la figurina, se lo staff l'ha già ritagliata
            'cutout_url' => $p->cutout_path ? url("/api/v1/public/players/{$p->id}/cutout") : null,
            'nationality' => $p->nationality,
            'is_active' => (bool) $p->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function player(Player $p): array
    {
        return self::publicPlayer($p) + [
            'first_name' => $p->first_name,
            'last_name' => $p->last_name,
            'in_squad_list' => (bool) $p->in_squad_list,
            'registration_status' => $p->registration_status,
            'medical_cert_expires_on' => $p->medical_cert_expires_on?->toDateString(),
            'phone' => $p->phone,
            'email' => $p->email,
            'birth_date' => $p->birth_date?->toDateString(),
            'xfive_player_id' => $p->xfive_player_id,
            'notes' => $p->notes,
            'scout_text' => $p->scout_text,
            'scout_source' => $p->scout_source,
            'scout_generated_at' => $p->scout_generated_at?->toIso8601String(),
            'magic_link' => rtrim((string) config('amir.frontend_url'), '/').'/p/'.$p->access_token,
        ];
    }

    /** @return array{yes:int,no:int,maybe:int,pending:int} */
    public static function eventSummary(TeamEvent $e): array
    {
        $counts = $e->responses()
            ->whereNotNull('rsvp')
            ->selectRaw('rsvp, COUNT(*) as c')
            ->groupBy('rsvp')
            ->pluck('c', 'rsvp');

        $yes = (int) ($counts['yes'] ?? 0);
        $no = (int) ($counts['no'] ?? 0);
        $maybe = (int) ($counts['maybe'] ?? 0);
        $active = Player::where('team_id', $e->team_id)->where('is_active', true)->count();

        return ['yes' => $yes, 'no' => $no, 'maybe' => $maybe, 'pending' => max(0, $active - $yes - $no - $maybe)];
    }

    /** @return array<string, mixed> */
    public static function event(TeamEvent $e, ?int $playerId = null): array
    {
        $data = [
            'id' => $e->id,
            'type' => $e->type,
            'title' => $e->title,
            'starts_at' => $e->starts_at?->toIso8601String(),
            'venue' => $e->venue,
            'match_id' => $e->match_id,
            'summary' => self::eventSummary($e),
        ];

        if ($playerId !== null) {
            $data['my_rsvp'] = $e->responses()->where('player_id', $playerId)->value('rsvp');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function response(EventResponse $r): array
    {
        return [
            'player_id' => $r->player_id,
            'player_name' => $r->player->full_name,
            'rsvp' => $r->rsvp,
            'attended' => $r->attended,
            'note' => $r->note,
            'responded_at' => $r->responded_at?->toIso8601String(),
        ];
    }

    /**
     * Una riga per ogni giocatore attivo, anche senza risposta (rsvp null).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function responsesFor(TeamEvent $e): array
    {
        $byPlayer = $e->responses()->get()->keyBy('player_id');

        return Player::where('team_id', $e->team_id)
            ->where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(function (Player $p) use ($byPlayer) {
                $r = $byPlayer->get($p->id);

                return [
                    'player_id' => $p->id,
                    'player_name' => $p->full_name,
                    'rsvp' => $r?->rsvp,
                    'attended' => $r?->attended,
                    'note' => $r?->note,
                    'responded_at' => $r?->responded_at?->toIso8601String(),
                ];
            })->values()->all();
    }

    /** @return array<string, mixed> */
    public static function lineup(Lineup $l): array
    {
        return [
            'match_id' => $l->match_id,
            'formation' => $l->formation,
            'slots' => $l->slots ?? [],
            'bench' => $l->bench ?? [],
            'notes' => $l->notes,
            'is_published' => (bool) $l->is_published,
        ];
    }
}
