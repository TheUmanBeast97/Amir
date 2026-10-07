<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Divisa che indossiamo in quella partita: 'red' | 'white' (decide il numero di maglia da mostrare)
        Schema::table('matches', function (Blueprint $table) {
            $table->string('our_kit', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('our_kit');
        });
    }
};
