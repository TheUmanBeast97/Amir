<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Tutti i giocatori della Mixed Zone, non solo quelli con un profilo XFive.
 *
 * XFive pubblica il profilo globale solo di chi è nella rosa attuale di un club (880 persone), ma nei referti ci sono
 * quasi 4.000 giocatori. Chi non ha un profilo diventa una persona «ricavata dai referti» (`derived`, id da 1.000.000),
 * identificata dal nome (lo slug di XFive); così ogni giocatore ha tutte le sue partite, in qualunque squadra.
 * Gli indici sullo slug servono a collegare righe e persone in una sola istruzione.
 *
 * Su un database che ha già i dati della Mixed Zone il collegamento parte subito (xfive:zone-link).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xf_players', function (Blueprint $table) {
            $table->boolean('derived')->default(false)->index();
            $table->index('slug', 'xf_players_slug_lookup');
        });
        Schema::table('xf_match_players', function (Blueprint $table) {
            $table->index('slug', 'xf_match_players_slug_idx');
        });
        Schema::table('xf_team_players', function (Blueprint $table) {
            $table->index('slug', 'xf_team_players_slug_idx');
        });

        if (DB::table('xf_match_players')->exists() || DB::table('xf_team_players')->exists()) {
            try {
                Artisan::call('xfive:zone-link');
            } catch (Throwable $e) {
                // il collegamento si può rifare a mano: non deve fermare il deploy
                Log::warning('Collegamento dei giocatori della Mixed Zone non riuscito: '.$e->getMessage());
            }
        }
    }

    public function down(): void
    {
        DB::table('xf_match_players')->whereIn('player_id', DB::table('xf_players')->where('derived', true)->select('id'))->update(['player_id' => null]);
        DB::table('xf_team_players')->whereIn('player_id', DB::table('xf_players')->where('derived', true)->select('id'))->update(['player_id' => null]);
        DB::table('xf_players')->where('derived', true)->delete();

        Schema::table('xf_team_players', fn (Blueprint $table) => $table->dropIndex('xf_team_players_slug_idx'));
        Schema::table('xf_match_players', fn (Blueprint $table) => $table->dropIndex('xf_match_players_slug_idx'));
        Schema::table('xf_players', function (Blueprint $table) {
            $table->dropIndex('xf_players_slug_lookup');
            $table->dropColumn('derived');
        });
    }
};
