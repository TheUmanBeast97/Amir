<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsToXfiveErrors;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\Xfive\Admin\SheetService;
use App\Services\Xfive\Admin\XfiveAdminException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** La distinta ufficiale di una partita (quella che si deposita su XFive) e i numeri di maglia ricordati dalle distinte passate. */
class SheetController extends Controller
{
    use RespondsToXfiveErrors;

    /** Stato della distinta: giocatori, numeri suggeriti, storico; se XFive la rende apribile adesso e se c'è il PDF. */
    public function show(Request $request, Game $game, SheetService $sheets): JsonResponse
    {
        try {
            return $this->ok($sheets->state($request->user(), $game));
        } catch (XfiveAdminException $e) {
            return $this->xfiveError($e);
        }
    }

    /** Invia la distinta a XFive: i giocatori scelti, con il numero di maglia di ciascuno. */
    public function save(Request $request, Game $game, SheetService $sheets): JsonResponse
    {
        $data = $request->validate([
            'players' => ['required', 'array', 'min:1', 'max:40'],
            'players.*.xfive_id' => ['required', 'integer'],
            'players.*.number' => ['required', 'string', 'max:8'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            return $this->ok($sheets->save($request->user(), $game, $data['players'], $data['note'] ?? null));
        } catch (XfiveAdminException $e) {
            return $this->xfiveError($e);
        }
    }

    /**
     * Il link al PDF della distinta su XFive, solo per lo staff: il sito pubblico mostra il pulsante a chi è collegato e lo chiede qui,
     * così l'indirizzo non compare mai nelle pagine pubbliche. 404 se per la partita non è stata inviata nessuna distinta.
     */
    public function pdf(Game $game, SheetService $sheets): JsonResponse
    {
        $url = $sheets->pdfUrlFor($game);

        return $url !== null
            ? $this->ok(['url' => $url])
            : response()->json(['message' => 'Nessuna distinta inviata per questa partita.'], 404);
    }

    /** Per ogni giocatore, l'ultimo numero e quelli delle distinte passate (non serve XFive). */
    public function numbers(SheetService $sheets): JsonResponse
    {
        return $this->ok($sheets->numbers());
    }
}
