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
        Schema::create('parcours_matieres', function (Blueprint $table) {
            $table->id();

            $table->foreignId('parcours_id')
                ->constrained('parcours_scolaires')
                ->cascadeOnDelete();

            $table->foreignId('matiere_id')
                ->constrained('matieres')
                ->cascadeOnDelete();

            $table->integer('ordre');
            $table->timestamps();

            $table->unique(['parcours_id', 'matiere_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parcours_matieres');
    }
};