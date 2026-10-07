<?php

namespace App\Services\Ai;

/** Qualunque servizio che scrive un testo a partire da istruzioni: nei test si sostituisce con uno finto. */
interface TextGenerator
{
    /** Vero se il servizio ha le credenziali per rispondere. */
    public function isConfigured(): bool;

    /**
     * @param  float|null  $temperature  0 = preciso e ripetibile, 1+ = creativo; vuoto = quella di default del servizio (per i testi social)
     *
     * @throws \RuntimeException se il servizio non risponde, rifiuta o restituisce un testo vuoto
     */
    public function generate(string $system, string $prompt, int $maxTokens = 2000, ?float $temperature = null): string;
}
