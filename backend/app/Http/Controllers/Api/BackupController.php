<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DatabaseBackup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Copia di sicurezza dei dati (solo staff): scarica tutti i dati o li ripristina da un file. */
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
}
