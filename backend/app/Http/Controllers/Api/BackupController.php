<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\DatabaseBackup;
use App\Services\LocalDataImporter;
use App\Support\SafeError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/** Copia di sicurezza dei dati (solo staff): scarica tutti i dati, li ripristina da un file o porta qui info e pagamenti del gestionale locale. */
class BackupController extends Controller
{
    public function download(DatabaseBackup $backup): Response
    {
        $name = 'amir-backup-'.now()->format('Y-m-d-His').'.json.gz';

        return response($backup->export(), 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function restore(Request $request, DatabaseBackup $backup): JsonResponse
    {
        $request->validate([
            // anche su un host senza limiti, un backup di questa squadra pesa pochi MB: oltre i 20 non può essere quello giusto
            'file' => ['required', 'file', 'max:20480'],
            'confirm' => ['required', 'in:RIPRISTINA'],
        ], [
            'confirm.in' => 'Per confermare scrivi RIPRISTINA in maiuscolo.',
        ]);

        try {
            $backup->restore($request->file('file')->getRealPath());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        return $this->ok(['restored' => true]);
    }

    /**
     * Unisce ai dati online le info dei giocatori e i pagamenti di un file del gestionale sul computer (database.sqlite o un backup).
     * Non sostituisce nulla: completa i campi vuoti e aggiunge quello che manca, quindi si può rilanciare senza fare doppioni.
     */
    public function importLocal(Request $request, LocalDataImporter $importer): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:20480']]);

        $own = Team::own();
        if (! $own) {
            throw ValidationException::withMessages(['file' => ['Squadra non configurata.']]);
        }

        try {
            $stats = $importer->import($own, $request->file('file')->getRealPath());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        } catch (Throwable $e) {
            // dentro una transazione: se qualcosa va storto non resta nulla a metà. Nel log niente SQL con i valori.
            Log::warning('Importazione dal gestionale locale non riuscita: '.SafeError::describe($e));

            throw ValidationException::withMessages(['file' => ['Importazione non riuscita: non è stato cambiato nulla.']]);
        }

        return $this->ok($stats);
    }
}
