<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - players.xfive_sheet_id: l'id con cui XFive identifica il giocatore nel modulo della distinta (diverso da xfive_admin_id, che è
 *   quello della rosa nell'area amministrazione). Si ricava dal modulo stesso, abbinando per nome e data di nascita.
 * - matches.sheet_published: la distinta ufficiale è visibile sul sito pubblico (al posto dei convocati).
 * - official_sheets.xfive_gtid: la squadra come la conosce XFive per quella partita, serve al link del PDF della distinta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedBigInteger('xfive_sheet_id')->nullable()->unique();
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->boolean('sheet_published')->default(false);
        });

        Schema::table('official_sheets', function (Blueprint $table) {
            $table->unsignedBigInteger('xfive_gtid')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('official_sheets', fn (Blueprint $table) => $table->dropColumn('xfive_gtid'));
        Schema::table('matches', fn (Blueprint $table) => $table->dropColumn('sheet_published'));
        Schema::table('players', function (Blueprint $table) {
            $table->dropUnique(['xfive_sheet_id']);
            $table->dropColumn('xfive_sheet_id');
        });
    }
};
