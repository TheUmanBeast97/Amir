<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            // quota_stagione | tesseramento | multa | arbitro | cena | altro
            $table->string('kind', 20)->default('altro');
            $table->unsignedInteger('amount_cents');
            $table->date('due_on')->nullable();
            $table->string('season', 9)->nullable();
            $table->timestamps();
        });

        Schema::create('player_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->timestamps();

            $table->unique(['charge_id', 'player_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_charge_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            // contanti | satispay | paypal | bonifico | altro
            $table->string('method', 12)->default('contanti');
            $table->date('paid_at');
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 10);
            $table->string('status', 10)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('player_charges');
        Schema::dropIfExists('charges');
    }
};
