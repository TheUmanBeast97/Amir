<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Modulistica;
use App\Services\ModulisticaAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** La modulistica XFive (area staff): analisi dei documenti, file originali e domande. */
class DocumentController extends Controller
{
    public function index(Modulistica $modulistica): JsonResponse
    {
        return $this->ok($modulistica->overview());
    }

    /** Il file originale (PDF o immagine), da aprire nel browser. */
    public function file(string $slug, Modulistica $modulistica): BinaryFileResponse
    {
        $doc = $modulistica->document($slug);
        $path = $modulistica->filePath($slug);
        abort_if($doc === null || $path === null, 404);

        $response = response()->file($path, [
            'Content-Type' => $doc['mime'],
            'Content-Disposition' => 'inline; filename="'.$doc['file'].'"',
        ]);
        // area staff: niente cache condivise (di default un file servito così sarebbe «public»)
        $response->headers->set('Cache-Control', 'private, no-cache');

        return $response;
    }

    public function ask(Request $request, ModulisticaAssistant $assistant): JsonResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->ok($assistant->ask($data['question']));
    }
}
