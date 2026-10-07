<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DatabaseBackup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Copia di sicurezza dei dati (solo staff): scarica tutto il database o lo ripristina da un file. */
class BackupController extends Controller
{
    public function download(DatabaseBackup $backup): BinaryFileResponse
    {
        $name = 'amir-backup-'.now()->format('Y-m-d-His').'.sqlite';

        return response()
            ->download($backup->export(), $name, ['Content-Type' => 'application/vnd.sqlite3'])
            ->deleteFileAfterSend(true);
    }

    public function restore(Request $request, DatabaseBackup $backup): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:262144'],
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
