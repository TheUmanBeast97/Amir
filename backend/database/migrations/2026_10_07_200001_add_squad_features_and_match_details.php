<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Competizioni: quelle "escluse" non entrano in storico, statistiche e presenze
        // (es. la Serie A a 8 di 100GRIGIO); le amichevoli non hanno un torneo XFive.
        Schema::table('competitions', function (Blueprint $table) {
            $table->boolean('is_excluded')->default(false)->index();
        });
        Schema::table('competitions', function (Blueprint $table) {
            // l'indice unico già presente resta valido: SQLite ammette più NULL
            $table->unsignedBigInteger('xfive_tournament_id')->nullable()->change();
        });

        // Un giocatore può avere un numero diverso a seconda della divisa.
        Schema::table('players', function (Blueprint $table) {
            $table->string('shirt_number_red', 4)->nullable();
            $table->string('shirt_number_white', 4)->nullable();
        });

        // Dati letti dalla pagina partita di XFive (arbitro, marcatori e formazioni di entrambe le squadre).
        Schema::table('matches', function (Blueprint $table) {
            $table->boolean('callups_published')->default(false);
            $table->json('details')->nullable();
            $table->timestamp('details_synced_at')->nullable();
        });

        Schema::table('lineups', function (Blueprint $table) {
            $table->boolean('is_published')->default(false);
        });

        // source: 'xfive' (importato, si può riscrivere) | 'manual' (inserito dallo staff, non si tocca)
        Schema::table('match_player_stats', function (Blueprint $table) {
            $table->boolean('is_mvp')->default(false);
            $table->string('source', 10)->default('manual');
        });

        Schema::create('match_callups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('note', 160)->nullable();
            $table->timestamps();

            $table->unique(['match_id', 'player_id']);
        });

        // I tornei esclusi dalla configurazione si marcano subito sui dati già presenti.
        $excluded = (array) config('amir.excluded_tournaments', []);
        if ($excluded !== []) {
            DB::table('competitions')->whereIn('xfive_tournament_id', $excluded)->update(['is_excluded' => true]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('match_callups');

        Schema::table('match_player_stats', function (Blueprint $table) {
            $table->dropColumn(['is_mvp', 'source']);
        });
        Schema::table('lineups', function (Blueprint $table) {
            $table->dropColumn('is_published');
        });
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['callups_published', 'details', 'details_synced_at']);
        });
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['shirt_number_red', 'shirt_number_white']);
        });
        Schema::table('competitions', function (Blueprint $table) {
            $table->dropColumn('is_excluded');
        });
    }
};
