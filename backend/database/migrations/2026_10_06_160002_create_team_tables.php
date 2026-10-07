<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('nickname')->nullable();
            $table->string('shirt_number', 4)->nullable();
            $table->string('role', 20)->nullable();
            $table->string('photo_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('in_squad_list')->default(false);
            // none | pending | approved
            $table->string('registration_status', 10)->default('none');
            $table->date('medical_cert_expires_on')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->date('birth_date')->nullable();
            $table->unsignedBigInteger('xfive_player_id')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->string('access_token', 64)->unique();
            $table->timestamps();

            $table->index(['team_id', 'is_active']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // match | training | social
            $table->string('type', 10);
            $table->string('title');
            $table->dateTime('starts_at')->nullable();
            $table->string('venue')->nullable();
            $table->foreignId('match_id')->nullable()->unique()->constrained('matches')->nullOnDelete();
            $table->timestamps();

            $table->index('starts_at');
        });

        Schema::create('event_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            // yes | no | maybe (null = nessuna risposta)
            $table->string('rsvp', 6)->nullable();
            $table->boolean('attended')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'player_id']);
        });

        Schema::create('lineups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->unique()->constrained('matches')->cascadeOnDelete();
            $table->string('formation', 12);
            $table->json('slots');
            $table->json('bench')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('match_player_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->boolean('played')->default(false);
            $table->unsignedSmallInteger('goals')->default(0);
            $table->unsignedSmallInteger('assists')->default(0);
            $table->unsignedSmallInteger('yellow')->default(0);
            $table->unsignedSmallInteger('red')->default(0);
            $table->decimal('rating', 3, 1)->nullable();
            $table->timestamps();

            $table->unique(['match_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_player_stats');
        Schema::dropIfExists('lineups');
        Schema::dropIfExists('event_responses');
        Schema::dropIfExists('events');
        Schema::dropIfExists('players');
    }
};
