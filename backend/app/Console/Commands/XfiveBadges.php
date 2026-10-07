<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\Game;
use App\Models\Team;
use App\Services\Xfive\ImageDownloader;
use Illuminate\Console\Command;

class XfiveBadges extends Command
{
    protected $signature = 'xfive:badges
        {--all : tutte le squadre note, anche quelle dello storico (default: solo la stagione in corso)}
        {--force : riscarica anche gli stemmi già salvati}';

    protected $description = 'Scarica gli stemmi delle squadre da XFive (500 px quando disponibili) e li salva in locale';

    public function handle(ImageDownloader $images): int
    {
        $teams = Team::whereNotNull('badge_url')->get();

        if (! $this->option('all')) {
            $currentGames = Game::whereIn('competition_id', Competition::where('is_current', true)->pluck('id'))->get(['home_team_id', 'away_team_id']);
            $ids = $currentGames->pluck('home_team_id')->merge($currentGames->pluck('away_team_id'))->unique();
            $teams = $teams->filter(fn (Team $t) => $t->is_own || $ids->contains($t->id));
        }

        $done = $skipped = $missing = 0;

        foreach ($teams as $team) {
            if ($team->badge_path && ! $this->option('force')) {
                $skipped++;

                continue;
            }

            $path = $images->store($team->badge_url, 'badge', $team->id);
            if ($path === null) {
                $missing++;
                $this->line("  senza stemma: {$team->name}");

                continue;
            }

            $team->update(['badge_path' => $path]);
            $done++;
        }

        $this->info("Stemmi scaricati: {$done} | già presenti: {$skipped} | non disponibili: {$missing}");

        return self::SUCCESS;
    }
}
