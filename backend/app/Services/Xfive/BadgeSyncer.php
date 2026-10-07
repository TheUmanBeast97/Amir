<?php

namespace App\Services\Xfive;

use App\Models\Competition;
use App\Models\Game;
use App\Models\Team;
use App\Services\MediaStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/** Scarica gli stemmi delle squadre da XFive (comando xfive:badges e aggiornamento dal sito). */
final class BadgeSyncer
{
    public function __construct(private readonly ImageDownloader $images, private readonly MediaStore $media) {}

    /**
     * Le squadre di cui serve lo stemma: la nostra e quelle della stagione in corso (con $all, tutte quelle note).
     *
     * @return Collection<int, Team>
     */
    public function teams(bool $all = false): Collection
    {
        $teams = Team::whereNotNull('badge_url')->get();

        if ($all) {
            return $teams;
        }

        $currentGames = Game::whereIn('competition_id', Competition::where('is_current', true)->pluck('id'))->get(['home_team_id', 'away_team_id']);
        $ids = $currentGames->pluck('home_team_id')->merge($currentGames->pluck('away_team_id'))->unique();

        return $teams->filter(fn (Team $t) => $t->is_own || $ids->contains($t->id));
    }

    /**
     * Scarica gli stemmi mancanti (o tutti, con $force). Chi su XFive non ha uno stemma vero non si riprova per una settimana.
     *
     * @param  Collection<int, Team>  $teams
     * @param  float|null  $deadline  istante (microtime) oltre il quale ci si ferma: il resto alla prossima volta
     * @return array{done:int, skipped:int, missing:array<int,string>, remaining:int}
     */
    public function sync(Collection $teams, bool $force = false, ?float $deadline = null): array
    {
        $result = ['done' => 0, 'skipped' => 0, 'missing' => [], 'remaining' => 0];

        // «già presente» vuol dire che l'immagine c'è davvero, non solo il percorso (dopo un ripristino i percorsi tornano, le immagini no)
        $stored = $this->media->existingPaths($teams->pluck('badge_path')->filter()->all());

        foreach ($teams as $team) {
            if ($team->badge_path && isset($stored[$team->badge_path]) && ! $force) {
                $result['skipped']++;

                continue;
            }
            if (! $force && Cache::has("amir:badge-missing:{$team->id}")) {
                $result['missing'][] = $team->name;

                continue;
            }
            if ($deadline !== null && microtime(true) > $deadline) {
                $result['remaining']++;

                continue;
            }

            $path = $this->images->store((string) $team->badge_url, 'badge', $team->id);
            if ($path === null) {
                Cache::put("amir:badge-missing:{$team->id}", true, now()->addWeek());
                $result['missing'][] = $team->name;

                continue;
            }

            $team->update(['badge_path' => $path]);
            $result['done']++;
        }

        return $result;
    }
}
