<?php

namespace App\Console\Commands;

use App\Models\Zone\XfMatchPlayer;
use App\Models\Zone\XfPlayer;
use App\Models\Zone\XfTeamPlayer;
use App\Services\Zone\ZoneImporter;
use Illuminate\Console\Command;

/**
 * Ricollega tutte le distinte e le rose della Mixed Zone alle persone (ZoneImporter::linkPlayers): ogni giocatore con le
 * sue partite in qualunque squadra, e una persona ricavata dai referti per chi XFive non dà un profilo. Idempotente; parte
 * da solo con la migrazione che aggiunge `derived`, e si rilancia a mano se serve.
 */
class XfiveZoneLink extends Command
{
    protected $signature = 'xfive:zone-link';

    protected $description = 'Collega distinte e rose della Mixed Zone alle persone, anche a chi non ha un profilo XFive';

    public function handle(ZoneImporter $importer): int
    {
        $started = microtime(true);
        $r = $importer->linkPlayers();

        $this->info(sprintf(
            'Persone ricavate dai referti create: %d. Righe di distinta collegate: %d. Righe di rosa collegate: %d (%.1f s).',
            $r['created'], $r['match_rows'], $r['roster_rows'], microtime(true) - $started,
        ));
        $this->line(sprintf(
            'Giocatori: %d (di cui %d ricavati dai referti). Distinte senza persona: %d (segnaposto «00 00» e simili).',
            XfPlayer::query()->count(), XfPlayer::query()->where('derived', true)->count(), XfMatchPlayer::query()->whereNull('player_id')->count(),
        ));
        $this->line(sprintf('Rose senza persona: %d.', XfTeamPlayer::query()->whereNull('player_id')->count()));

        return self::SUCCESS;
    }
}
