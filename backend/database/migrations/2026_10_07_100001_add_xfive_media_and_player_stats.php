<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // stemma scaricato da XFive (percorso relativo al disco "local")
            $table->string('badge_path')->nullable();
        });

        Schema::table('players', function (Blueprint $table) {
            // id del profilo globale XFive (/it/player-info/{id}/): diverso da xfive_player_id
            $table->unsignedBigInteger('xfive_person_id')->nullable()->unique();
            $table->string('nationality', 60)->nullable();
            $table->string('photo_path')->nullable();
            // {age, role, tournaments:[{id,name,season,format}]} del nostro club
            $table->json('xfive_profile')->nullable();
            $table->timestamp('xfive_synced_at')->nullable();
        });

        Schema::create('player_competition_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('goals')->default(0);
            $table->unsignedSmallInteger('yellow')->default(0);
            $table->unsignedSmallInteger('red')->default(0);
            // punti della classifica "Miglior giocatore" di XFive
            $table->unsignedSmallInteger('mvp_points')->default(0);
            $table->timestamps();

            $table->unique(['player_id', 'competition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_competition_stats');

        Schema::table('players', function (Blueprint $table) {
            $table->dropUnique(['xfive_person_id']);
            $table->dropColumn(['xfive_person_id', 'nationality', 'photo_path', 'xfive_profile', 'xfive_synced_at']);
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('badge_path');
        });
    }
};
