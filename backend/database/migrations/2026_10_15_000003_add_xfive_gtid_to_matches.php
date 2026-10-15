<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * matches.xfive_gtid: la squadra come la conosce XFive per quella partita (serve al link del PDF della distinta). Si impara quando
 * lo staff apre su XFive una distinta già compilata, anche se non è stata inviata da qui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->unsignedBigInteger('xfive_gtid')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('matches', fn (Blueprint $table) => $table->dropColumn('xfive_gtid'));
    }
};
