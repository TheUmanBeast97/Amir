<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\Payment;
use App\Models\Player;
use App\Models\PlayerCharge;
use App\Models\Team;
use App\Services\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Registro quote e pagamenti (nessun incasso reale: solo contabilità di squadra). */
class FinanceController extends Controller
{
    private const KINDS = ['quota_stagione', 'tesseramento', 'multa', 'arbitro', 'cena', 'altro'];

    private const METHODS = ['contanti', 'satispay', 'paypal', 'revolut', 'bonifico', 'altro'];

    public function __construct(private readonly FinanceService $finance) {}

    public function charges(): JsonResponse
    {
        $charges = Charge::with('playerCharges.payments')
            ->where('team_id', Team::ownOrFail()->id)
            ->orderByDesc('created_at')
            ->get();

        return $this->ok($charges->map(fn (Charge $c) => $this->finance->presentCharge($c))->values()->all());
    }

    public function storeCharge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'kind' => ['sometimes', Rule::in(self::KINDS)],
            'amount_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
            'due_on' => ['nullable', 'date'],
            'season' => ['nullable', 'string', 'max:9'],
            'player_ids' => ['nullable', 'array'],
            'player_ids.*' => ['integer', 'exists:players,id'],
        ]);

        $charge = $this->finance->createCharge(Team::ownOrFail(), $data, $data['player_ids'] ?? null);

        return $this->ok($this->finance->presentCharge($charge), 201);
    }

    public function showCharge(Charge $charge): JsonResponse
    {
        return $this->ok($this->finance->presentCharge($charge));
    }

    public function destroyCharge(Charge $charge): JsonResponse
    {
        $charge->delete();

        return $this->ok(new \stdClass);
    }

    public function assign(Request $request, Charge $charge): JsonResponse
    {
        $data = $request->validate([
            'player_ids' => ['nullable', 'array'],
            'player_ids.*' => ['integer', 'exists:players,id'],
            'amount_cents' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ]);

        $charge = $this->finance->assign($charge, $data['player_ids'] ?? null, $data['amount_cents'] ?? null);

        return $this->ok($this->finance->presentCharge($charge));
    }

    public function addPayment(Request $request, PlayerCharge $playerCharge): JsonResponse
    {
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1', 'max:10000000'],
            'method' => ['sometimes', Rule::in(self::METHODS)],
            'paid_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $updated = $this->finance->addPayment($playerCharge, $data);

        return $this->ok($this->finance->presentPlayerCharge($updated), 201);
    }

    public function destroyPayment(Payment $payment): JsonResponse
    {
        $payment->delete();

        return $this->ok(new \stdClass);
    }

    public function summary(): JsonResponse
    {
        return $this->ok($this->finance->summary(Team::ownOrFail()));
    }

    public function playerBalance(Player $player): JsonResponse
    {
        return $this->ok($this->finance->playerBalance($player));
    }
}
