<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Payment;
use App\Models\Player;
use App\Models\PlayerCharge;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registro quote e pagamenti. NON incassa denaro: tiene solo traccia di chi
 * deve cosa e di quanto è stato pagato (importi in centesimi).
 */
final class FinanceService
{
    /**
     * @param  array{title:string,kind?:string,amount_cents:int,due_on?:?string,season?:?string}  $data
     * @param  array<int,int>|null  $playerIds  null = tutti i giocatori attivi
     */
    public function createCharge(Team $team, array $data, ?array $playerIds = null): Charge
    {
        return DB::transaction(function () use ($team, $data, $playerIds) {
            $charge = Charge::create([
                'team_id' => $team->id,
                'title' => $data['title'],
                'kind' => $data['kind'] ?? 'altro',
                'amount_cents' => $data['amount_cents'],
                'due_on' => $data['due_on'] ?? null,
                'season' => $data['season'] ?? config('amir.xfive.current_season'),
            ]);

            $this->assign($charge, $playerIds, null);

            return $charge->refresh();
        });
    }

    /** Assegna la voce ai giocatori (senza duplicare quelli già assegnati). */
    public function assign(Charge $charge, ?array $playerIds, ?int $amountCents): Charge
    {
        $players = Player::where('team_id', $charge->team_id)
            ->when($playerIds === null, fn ($q) => $q->where('is_active', true))
            ->when($playerIds !== null, fn ($q) => $q->whereIn('id', $playerIds))
            ->get();

        foreach ($players as $player) {
            PlayerCharge::firstOrCreate(
                ['charge_id' => $charge->id, 'player_id' => $player->id],
                ['amount_cents' => $amountCents ?? $charge->amount_cents],
            );
        }

        return $charge->refresh();
    }

    /** @param array{amount_cents:int,method?:string,paid_at:string,note?:?string} $data */
    public function addPayment(PlayerCharge $pc, array $data): PlayerCharge
    {
        $pc->payments()->create([
            'amount_cents' => $data['amount_cents'],
            'method' => $data['method'] ?? 'contanti',
            'paid_at' => $data['paid_at'],
            'note' => $data['note'] ?? null,
        ]);

        return $pc->load(['charge', 'payments']);
    }

    /** @return array<string, mixed> */
    public function presentCharge(Charge $charge): array
    {
        $charge->loadMissing('playerCharges.payments');

        return [
            'id' => $charge->id,
            'title' => $charge->title,
            'kind' => $charge->kind,
            'amount_cents' => $charge->amount_cents,
            'due_on' => $charge->due_on?->toDateString(),
            'season' => $charge->season,
            'assigned_count' => $charge->playerCharges->count(),
            'total_due_cents' => (int) $charge->playerCharges->sum('amount_cents'),
            'total_paid_cents' => (int) $charge->playerCharges->sum(fn (PlayerCharge $pc) => $pc->paidCents()),
        ];
    }

    /** @return array<string, mixed> */
    public function presentPlayerCharge(PlayerCharge $pc): array
    {
        $pc->loadMissing(['charge', 'payments']);
        $paid = $pc->paidCents();
        $balance = $pc->amount_cents - $paid;

        return [
            'id' => $pc->id,
            'charge' => [
                'id' => $pc->charge->id,
                'title' => $pc->charge->title,
                'kind' => $pc->charge->kind,
                'due_on' => $pc->charge->due_on?->toDateString(),
            ],
            'player_id' => $pc->player_id,
            'amount_cents' => $pc->amount_cents,
            'paid_cents' => $paid,
            'balance_cents' => $balance,
            'is_overdue' => $balance > 0 && $pc->charge->due_on !== null && $pc->charge->due_on->isPast() && ! $pc->charge->due_on->isToday(),
            'payments' => $pc->payments->sortBy('paid_at')->map(fn (Payment $p) => [
                'id' => $p->id,
                'player_charge_id' => $p->player_charge_id,
                'amount_cents' => $p->amount_cents,
                'method' => $p->method,
                'paid_at' => $p->paid_at->toDateString(),
                'note' => $p->note,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function playerBalance(Player $player): array
    {
        $items = PlayerCharge::with(['charge', 'payments'])
            ->where('player_id', $player->id)
            ->get()
            ->map(fn (PlayerCharge $pc) => $this->presentPlayerCharge($pc))
            ->sortBy(fn (array $i) => $i['charge']['due_on'] ?? '9999-12-31')
            ->values();

        $due = (int) $items->sum('amount_cents');
        $paid = (int) $items->sum('paid_cents');

        return [
            'player_id' => $player->id,
            'player_name' => $player->full_name,
            'due_cents' => $due,
            'paid_cents' => $paid,
            'balance_cents' => $due - $paid,
            'items' => $items->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(Team $team): array
    {
        $players = Player::where('team_id', $team->id)->where('is_active', true)->orderBy('last_name')->get();

        $rows = $players->map(function (Player $p) {
            $b = $this->playerBalance($p);
            unset($b['items']);

            return $b;
        });

        $due = (int) $rows->sum('due_cents');
        $paid = (int) $rows->sum('paid_cents');

        return [
            'total_due_cents' => $due,
            'total_paid_cents' => $paid,
            'total_outstanding_cents' => $due - $paid,
            'players' => $rows->sortByDesc('balance_cents')->values()->all(),
        ];
    }

    /** @return array{outstanding_cents:int, overdue_count:int} */
    public function dashboardTotals(Team $team): array
    {
        /** @var Collection<int, PlayerCharge> $items */
        $items = PlayerCharge::with(['charge', 'payments'])
            ->whereHas('player', fn ($q) => $q->where('team_id', $team->id)->where('is_active', true))
            ->get();

        $outstanding = 0;
        $overdue = 0;
        foreach ($items as $pc) {
            $balance = $pc->balanceCents();
            if ($balance <= 0) {
                continue;
            }
            $outstanding += $balance;
            if ($pc->charge->due_on !== null && $pc->charge->due_on->isPast() && ! $pc->charge->due_on->isToday()) {
                $overdue++;
            }
        }

        return ['outstanding_cents' => $outstanding, 'overdue_count' => $overdue];
    }
}
