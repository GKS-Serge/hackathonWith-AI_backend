<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('parcours_scolaires', function (Blueprint $table) {
            $table->id();

            $table->foreignId('niveau_id')
                ->constrained('niveaux')
                ->cascadeOnDelete();

            $table->foreignId('serie_id')
                ->constrained('series')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['niveau_id', 'serie_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parcours_scolaires');
    }
};