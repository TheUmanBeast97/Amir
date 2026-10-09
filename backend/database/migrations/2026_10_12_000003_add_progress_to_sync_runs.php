<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'avanzamento di un aggiornamento ({section, message, done, total}, scritto da XfiveRoutine al massimo ogni 2 s) e
 * uno «scope» più largo: gli scope della Mixed Zone (zone-tournaments, zone-standings...) non stanno in 10 caratteri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->json('progress')->nullable()->after('stats');
        });
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->string('scope', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropColumn('progress');
        });
    }
};
