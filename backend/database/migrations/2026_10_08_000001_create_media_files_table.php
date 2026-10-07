<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stemmi e foto scaricati da XFive. Stanno nel database e non su disco perché sul server (Vercel) il disco non resta:
 * i file sono piccoli (qualche decina di KB), quindi si conservano in base64 in una colonna di testo, uguale su ogni database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->string('path')->primary();
            $table->string('mime', 64);
            $table->unsignedInteger('bytes');
            $table->longText('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
