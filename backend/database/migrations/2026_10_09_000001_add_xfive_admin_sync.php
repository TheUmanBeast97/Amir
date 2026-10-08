<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lettura dell'area amministrazione di XFive (rosa, tesseramenti, certificati, Squad List): per ogni giocatore
 * l'identificativo nell'area amministrazione, lo stato del tesseramento e quando è stato letto.
 * Nessuna credenziale né sessione di XFive viene salvata: a ogni lettura si fa un accesso nuovo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->unsignedBigInteger('xfive_admin_id')->nullable()->unique();
            $table->json('xfive_membership')->nullable();
            $table->timestamp('xfive_admin_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropUnique(['xfive_admin_id']);
            $table->dropColumn(['xfive_admin_id', 'xfive_membership', 'xfive_admin_synced_at']);
        });
    }
};
