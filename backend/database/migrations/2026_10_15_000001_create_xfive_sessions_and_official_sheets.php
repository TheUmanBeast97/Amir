<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La distinta ufficiale (quella che si deposita su XFive prima della gara):
 * - xfive_sessions: l'accesso a XFive di chi dello staff ha fatto «Collega XFive». Si conserva solo il cookie di sessione, cifrato
 *   e con una scadenza; la password non si salva mai.
 * - official_sheets e official_sheet_players: ogni distinta inviata, con i numeri di maglia, così si ricordano per le volte dopo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xfive_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('email_hint', 120);
            $table->text('cookies');
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('official_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->string('xfive_message', 255)->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['match_id', 'sent_at']);
        });

        Schema::create('official_sheet_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('official_sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('xfive_admin_id')->nullable();
            $table->string('name', 120);
            $table->string('number', 8);
            $table->timestamps();

            $table->index('player_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('official_sheet_players');
        Schema::dropIfExists('official_sheets');
        Schema::dropIfExists('xfive_sessions');
    }
};
