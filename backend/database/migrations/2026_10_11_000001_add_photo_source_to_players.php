<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Da dove viene la foto del giocatore e quando è cambiata l'ultima volta.
 *  - photo_source: null o «xfive» = scaricata da XFive (gli aggiornamenti la rinfrescano); «upload» = caricata dallo staff
 *    (XFive non la tocca più); «none» = tolta dallo staff (non si riscarica da sola finché non si preme «Aggiorna da XFive»).
 *  - photo_updated_at: entra nell'indirizzo della foto, così browser e rete di Vercel non mostrano quella vecchia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->string('photo_source', 10)->nullable();
            $table->timestamp('photo_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['photo_source', 'photo_updated_at']);
        });
    }
};
