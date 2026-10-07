<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** La scheda scout di un giocatore: un breve testo scritto dall'IA (o da un modello) partendo dai suoi numeri, generato dallo staff. */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->text('scout_text')->nullable();
            $table->string('scout_source', 16)->nullable(); // "ai" oppure "template"
            $table->timestamp('scout_generated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['scout_text', 'scout_source', 'scout_generated_at']);
        });
    }
};
