<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\Team;
use App\Services\PlayerImporter;
use App\Services\ScoutProfileGenerator;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlayerController extends Controller
{
    private const ROLES = ['portiere', 'difensore', 'centrocampista', 'attaccante', 'dirigente', 'allenatore'];

    public function index(Request $request): JsonResponse
    {
        $own = Team::ownOrFail();

        $players = Player::where('team_id', $own->id)
            ->when($request->query('active') !== null, fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->when($request->query('squad') !== null, fn ($q) => $q->where('in_squad_list', $request->boolean('squad')))
            ->when($request->filled('registration'), fn ($q) => $q->where('registration_status', $request->query('registration')))
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->query('role')))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return $this->ok($players->map(fn (Player $p) => Present::player($p))->values()->all());
    }

    public function store(Request $request): JsonResponse
    {
        $own = Team::ownOrFail();
        $data = $request->validate($this->rules(null, true));
        $this->guardSquadList($data, null, $own);

        $player = Player::create($data + ['team_id' => $own->id]);

        return $this->ok(Present::player($player), 201);
    }

    public function show(Player $player): JsonResponse
    {
        return $this->ok(Present::player($player));
    }

    public function update(Request $request, Player $player): JsonResponse
    {
        $data = $request->validate($this->rules($player, false));
        $this->guardSquadList($data, $player, Team::ownOrFail());

        $player->update($data);

        return $this->ok(Present::player($player->refresh()));
    }

    /**
     * Di norma disattiva il giocatore (così restano storico, presenze e quote).
     * Con ?force=1 lo elimina davvero, con tutto ciò che lo riguarda.
     */
    public function destroy(Request $request, Player $player): JsonResponse
    {
        if ($request->boolean('force')) {
            $player->delete();
        } else {
            $player->update(['is_active' => false, 'in_squad_list' => false]);
        }

        return $this->ok(new \stdClass);
    }

    /**
     * Scrive e salva la scheda scout del giocatore: con la chiave Gemini la scrive l'IA, altrimenti un modello di testo.
     * La risposta dice quale dei due è stato usato; il testo si può poi correggere con saveScout.
     */
    public function scout(Player $player, ScoutProfileGenerator $generator): JsonResponse
    {
        $result = $generator->generate($player, Team::ownOrFail());
        $player->update(['scout_text' => $result['text'], 'scout_source' => $result['source'], 'scout_generated_at' => now()]);

        return $this->ok($result + ['player' => Present::player($player->refresh())]);
    }

    /** Salva il testo della scheda scout corretto a mano; senza testo la scheda viene tolta. */
    public function saveScout(Request $request, Player $player): JsonResponse
    {
        $data = $request->validate(['text' => ['nullable', 'string', 'max:700']]);
        $text = trim((string) ($data['text'] ?? ''));

        $player->update($text === ''
            ? ['scout_text' => null, 'scout_source' => null, 'scout_generated_at' => null]
            : ['scout_text' => $text, 'scout_source' => 'manual', 'scout_generated_at' => now()]);

        return $this->ok(Present::player($player->refresh()));
    }

    public function import(Request $request, PlayerImporter $importer): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*' => ['array'],
        ]);

        return $this->ok($importer->import(Team::ownOrFail(), $data['rows']));
    }

    /** @return array<string, mixed> */
    private function rules(?Player $player, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'first_name' => [$required, 'string', 'max:100'],
            'last_name' => [$required, 'string', 'max:100'],
            'nickname' => ['nullable', 'string', 'max:60'],
            'shirt_number' => ['nullable', 'string', 'max:4'],
            // il numero può cambiare a seconda della divisa che indossiamo
            'shirt_number_red' => ['nullable', 'string', 'max:4'],
            'shirt_number_white' => ['nullable', 'string', 'max:4'],
            'role' => ['nullable', Rule::in(self::ROLES)],
            'photo_url' => ['nullable', 'url', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'in_squad_list' => ['sometimes', 'boolean'],
            'registration_status' => ['sometimes', Rule::in(['none', 'pending', 'approved'])],
            'medical_cert_expires_on' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'],
            'birth_date' => ['nullable', 'date'],
            'xfive_player_id' => ['nullable', 'integer', Rule::unique('players', 'xfive_player_id')->ignore($player?->id)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** XFive ammette al massimo 10 giocatori "esclusivi" in Squad List. */
    private function guardSquadList(array $data, ?Player $player, Team $own): void
    {
        if (! filter_var($data['in_squad_list'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $limit = (int) config('amir.squad_list_limit');
        $count = Player::where('team_id', $own->id)
            ->where('is_active', true)
            ->where('in_squad_list', true)
            ->when($player, fn ($q) => $q->where('id', '!=', $player->id))
            ->count();

        if ($count >= $limit) {
            throw ValidationException::withMessages([
                'in_squad_list' => ["La Squad List è già completa ({$limit} giocatori esclusivi). Toglierne uno prima di aggiungerne un altro."],
            ]);
        }
    }
}
