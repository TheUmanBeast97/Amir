<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Zone\XfClub;
use App\Models\Zone\XfMatch;
use App\Models\Zone\XfPlayer;
use App\Models\Zone\XfSyncState;
use App\Models\Zone\XfTeam;
use App\Models\Zone\XfTournament;
use App\Services\Zone\ZoneImporter;
use App\Support\SafeError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/** Caricamento dell'archivio XFive nella Mixed Zone (solo staff): un pezzo dell'export alla volta, e lo stato delle tabelle xf_*. */
class ZoneImportController extends Controller
{
    /** Un pezzo zone-NNN-sezione.json.gz (multipart «file», al massimo 4 MB): risposta {section, items, counts}. */
    public function chunk(Request $request, ZoneImporter $importer): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:4096']]);

        try {
            $result = $importer->chunk((string) file_get_contents($request->file('file')->getRealPath()));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        } catch (Throwable $e) {
            // dentro una transazione: il pezzo non è stato scritto nemmeno in parte; si può rimandare. Nel log niente SQL con i valori.
            Log::warning('Caricamento di un pezzo della Mixed Zone non riuscito: '.SafeError::describe($e));

            throw ValidationException::withMessages(['file' => ['Caricamento non riuscito: il pezzo non è stato scritto, riprova.']]);
        }

        return $this->ok($result);
    }

    /** Quanto c'è nelle tabelle xf_* e l'ultimo caricamento o aggiornamento di ogni sezione. */
    public function status(): JsonResponse
    {
        return $this->ok([
            'tournaments' => XfTournament::count(),
            'clubs' => XfClub::count(),
            'teams' => XfTeam::count(),
            'players' => XfPlayer::count(),
            'matches' => XfMatch::count(),
            'reports' => XfMatch::where('has_report', true)->count(),
            'sections' => XfSyncState::query()->orderBy('section')->get()->map(fn (XfSyncState $s) => [
                'section' => $s->section,
                'synced_at' => $s->synced_at?->toIso8601String(),
                'counts' => (object) ($s->counts ?? []),
            ])->values()->all(),
        ]);
    }
}
