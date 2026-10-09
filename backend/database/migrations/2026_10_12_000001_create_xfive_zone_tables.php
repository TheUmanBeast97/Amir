<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** I dati pubblici di XFive per la Mixed Zone: tabelle separate da quelle di AMIR, con gli id di XFive come chiavi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xf_seasons', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary(); // sid XFive
            $t->string('label', 9); // 2026/2027
            $t->timestamps();
        });
        Schema::create('xf_tournaments', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('season_id')->index();
            $t->string('name');
            $t->string('slug');
            $t->string('sport'); // «Calcio a 8 - Maschile»
            $t->unsignedTinyInteger('format')->nullable(); // 5, 7, 8, 9, 11
            $t->string('gender', 20)->nullable();
            $t->string('category')->nullable();
            $t->string('flyer_url', 500)->nullable();
            $t->unsignedSmallInteger('teams_count')->nullable();
            $t->string('dates')->nullable();
            $t->string('status', 12)->default('previous'); // ongoing, incoming, previous
            $t->timestamp('calendar_synced_at')->nullable();
            $t->timestamp('standings_synced_at')->nullable();
            $t->timestamp('stats_synced_at')->nullable();
            $t->timestamp('teams_synced_at')->nullable();
            $t->timestamps();
        });
        Schema::create('xf_clubs', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('badge_url', 500)->nullable();
            $t->timestamps();
        });
        Schema::create('xf_teams', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary(); // id della squadra nel torneo
            $t->unsignedInteger('tournament_id')->index();
            $t->unsignedInteger('club_id')->nullable()->index();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('badge_url', 500)->nullable();
            $t->json('staff')->nullable();
            $t->timestamps();
        });
        Schema::create('xf_players', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary(); // pid globale
            $t->string('name');
            $t->string('slug')->nullable()->index();
            $t->string('photo_url', 500)->nullable();
            $t->unsignedSmallInteger('age')->nullable();
            $t->string('nationality')->nullable();
            $t->json('profile')->nullable(); // club e tornei dal profilo
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
        });
        Schema::create('xf_team_players', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('team_id')->index();
            $t->unsignedInteger('tpid'); // id del giocatore nel torneo
            $t->unsignedInteger('player_id')->nullable()->index();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('role')->nullable();
            $t->string('number', 4)->nullable();
            $t->string('photo_url', 500)->nullable();
            $t->string('country')->nullable();
            $t->unique(['team_id', 'tpid']);
        });
        Schema::create('xf_matches', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary(); // mid
            $t->unsignedInteger('tournament_id')->index();
            $t->unsignedSmallInteger('round')->nullable();
            $t->string('round_label')->nullable();
            $t->unsignedInteger('round_id')->nullable();
            $t->unsignedInteger('home_club_id')->nullable()->index();
            $t->unsignedInteger('away_club_id')->nullable()->index();
            $t->string('home_name');
            $t->string('away_name');
            $t->timestamp('kickoff_at')->nullable()->index();
            $t->string('kickoff_raw')->nullable();
            $t->string('venue')->nullable();
            $t->unsignedTinyInteger('home_score')->nullable();
            $t->unsignedTinyInteger('away_score')->nullable();
            $t->boolean('played')->default(false);
            $t->string('referee')->nullable();
            $t->boolean('has_report')->default(false);
            $t->timestamps();
        });
        Schema::create('xf_match_players', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('match_id')->index();
            $t->string('side', 4); // home, away
            $t->unsignedInteger('tpid');
            $t->unsignedInteger('player_id')->nullable()->index();
            $t->string('name');
            $t->string('slug')->nullable();
            $t->unsignedTinyInteger('goals')->default(0);
            $t->unsignedTinyInteger('yellow')->default(0);
            $t->unsignedTinyInteger('red')->default(0);
            $t->boolean('mvp')->default(false);
            $t->string('photo_url', 500)->nullable();
            $t->unique(['match_id', 'tpid']);
        });
        Schema::create('xf_standings', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('tournament_id')->index();
            $t->string('group')->nullable();
            $t->unsignedSmallInteger('position');
            $t->unsignedInteger('club_id')->nullable()->index();
            $t->string('name');
            $t->string('badge_url', 500)->nullable();
            $t->json('values'); // Pt, G, V, N, P, F, S, +/-, FP
            $t->unique(['tournament_id', 'group', 'position']);
        });
        Schema::create('xf_player_stats', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('tournament_id')->index();
            $t->string('type', 12); // score, top-player, discipline
            $t->unsignedSmallInteger('position');
            $t->string('name');
            $t->string('team')->nullable();
            $t->string('photo_url', 500)->nullable();
            $t->json('values');
            $t->unique(['tournament_id', 'type', 'position']);
        });
        Schema::create('xf_documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('tournament_id')->index();
            $t->string('url', 500);
            $t->string('title')->nullable();
            $t->unique(['tournament_id', 'url']);
        });
        Schema::create('xf_sync_state', function (Blueprint $t) {
            $t->string('section', 32)->primary();
            $t->timestamp('synced_at')->nullable();
            $t->json('counts')->nullable();
            $t->json('cursor')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['xf_sync_state', 'xf_documents', 'xf_player_stats', 'xf_standings', 'xf_match_players', 'xf_matches', 'xf_team_players', 'xf_players', 'xf_teams', 'xf_clubs', 'xf_tournaments', 'xf_seasons'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
