<?php

namespace App\Console\Commands;

use App\Services\Xfive\BadgeSyncer;
use Illuminate\Console\Command;

class XfiveBadges extends Command
{
    protected $signature = 'xfive:badges
        {--all : tutte le squadre note, anche quelle dello storico (default: solo la stagione in corso)}
        {--force : riscarica anche gli stemmi già salvati}';

    protected $description = 'Scarica gli stemmi delle squadre da XFive (500 px quando disponibili) e li salva nel database';

    public function handle(BadgeSyncer $badges): int
    {
        $r = $badges->sync($badges->teams((bool) $this->option('all')), (bool) $this->option('force'));

        foreach ($r['missing'] as $name) {
            $this->line("  senza stemma: {$name}");
        }

        $this->info("Stemmi scaricati: {$r['done']} | già presenti: {$r['skipped']} | non disponibili: ".count($r['missing']));

        return self::SUCCESS;
    }
}
