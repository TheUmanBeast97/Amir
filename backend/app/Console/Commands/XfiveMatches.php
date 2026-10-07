<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\Game;
use App\Models\Team;
use App\Services\Xfive\MatchDetailsSyncer;
use Illuminate\Console\Command;

class XfiveMatches extends Command
{
    protected $signature = 'xfive:matches
        {--all : rilegge anche le partite di cui abbiamo già i dettagli}
        {--recent : solo partite senza dettagli o giocate negli ultimi 10 giorni (per l\'esecuzione automatica)}
        {--include-excluded : legge anche i tornei esclusi dalle statistiche (es. Serie A a 8)}
        {--limit=0 : massimo di partite da leggere (0 = tutte)}';

    protected $description = 'Legge da XFive arbitro, distinta, marcatori e cartellini delle nostre partite giocate';

    public function handle(MatchDetailsSyncer $syncer): int
    {
        $own = Team::own();
        if (! $own) {
            $this->error('Squadra non configurata: esegui prima "php artisan db:seed".');

            return self::FAILURE;
        }

        $games = Game::with(['competition', 'home', 'away'])
            ->involving($own->id)
            ->where('status', Game::PLAYED)
            ->whereNotNull('xfive_match_id')
            ->whereHas('competition', function ($c) {
                $c->where('kind', '!=', Competition::KIND_FRIENDLY);
                if (! $this->option('include-excluded')) {
                    $c->where('is_excluded', false);
                }
            })
            ->when(! $this->option('all'), function ($q) {
                $q->where(function ($w) {
                    $w->whereNull('details_synced_at');
                    if ($this->option('recent')) {
                        $w->orWhere('kickoff_at', '>=', now()->subDays(10));
                    }
                });
            })
            ->orderBy('kickoff_at')
            ->when((int) $this->option('limit') > 0, fn ($q) => $q->limit((int) $this->option('limit')))
            ->get();

        if ($games->isEmpty()) {
            $this->info('Niente da leggere: tutte le partite hanno già i dettagli.');

            return self::SUCCESS;
        }

        $this->info("Leggo {$games->count()} partite da XFive (circa ".ceil($games->count() * 1.4 / 60).' minuti)…');
        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $r = $syncer->syncMany($games, $own, fn () => $bar->advance());
        $bar->finish();
        $this->newLine(2);

        $this->line("  partite lette: {$r['matches']} | senza distinta: {$r['no_lineup']} | righe statistiche: {$r['stats']}");
        $this->line("  ex giocatori creati: {$r['players_created']} | risultato diverso dal nostro: {$r['mismatch']}");
        foreach ($r['errors'] as $error) {
            $this->warn("  errore: {$error}");
        }

        return $r['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
