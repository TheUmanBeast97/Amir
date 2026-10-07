<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('xfive_club_id')->nullable()->unique();
            $table->unsignedBigInteger('xfive_public_id')->nullable();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('badge_url')->nullable();
            $table->boolean('is_own')->default(false);
            $table->string('kit1_color', 9)->nullable();
            $table->string('kit2_color', 9)->nullable();
            $table->unsignedTinyInteger('format')->nullable();
            $table->timestamps();
        });

        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('xfive_tournament_id')->unique();
            $table->string('name');
            $table->string('season', 9)->index();
            $table->string('kind', 20)->default('campionato');
            $table->unsignedTinyInteger('format')->default(8);
            $table->boolean('is_current')->default(false);
            $table->boolean('has_own_team')->default(false);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('total_rounds')->nullable();
            $table->string('xfive_url')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('xfive_match_id')->nullable()->unique();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('round')->nullable();
            $table->string('round_label')->nullable();
            $table->foreignId('home_team_id')->constrained('teams');
            $table->foreignId('away_team_id')->constrained('teams');
            $table->dateTime('kickoff_at')->nullable();
            $table->string('venue')->nullable();
            $table->unsignedSmallInteger('home_score')->nullable();
            $table->unsignedSmallInteger('away_score')->nullable();
            // scheduled | to_schedule | played | postponed | cancelled
            $table->string('status', 20)->default('to_schedule')->index();
            $table->string('referee')->nullable();
            $table->unsignedBigInteger('man_of_the_match_id')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['competition_id', 'round']);
            $table->index('kickoff_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
        Schema::dropIfExists('competitions');
        Schema::dropIfExists('teams');
    }
};
