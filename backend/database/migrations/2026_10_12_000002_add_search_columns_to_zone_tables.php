<?php

use App\Services\Zone\ZoneImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La colonna `search` per la ricerca della Mixed Zone (nome normalizzato: ascii minuscolo senza accenti, così «caffe km0»
 * trova «CAFFÈ KM0») su club, giocatori e tornei, riempita subito per le righe già presenti; più l'indice
 * xf_matches(tournament_id, kickoff_at) per calendari, ultime e prossime partite dei tornei.
 */
return new class extends Migration
{
    private const TABLES = ['xf_clubs', 'xf_players', 'xf_tournaments'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('search')->nullable()->index();
            });
        }
        Schema::table('xf_matches', function (Blueprint $t) {
            $t->index(['tournament_id', 'kickoff_at']);
        });

        // le righe già importate: il nome normalizzato come lo scrive ZoneImporter da qui in poi
        foreach (self::TABLES as $table) {
            DB::table($table)->select('id', 'name')->orderBy('id')->chunk(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(['search' => ZoneImporter::normalize((string) $row->name)]);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('xf_matches', function (Blueprint $t) {
            $t->dropIndex(['tournament_id', 'kickoff_at']);
        });
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['search']);
                $t->dropColumn('search');
            });
        }
    }
};
