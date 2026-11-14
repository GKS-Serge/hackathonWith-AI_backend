<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('progressions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('etudiant_id')
                ->constrained('etudiants')
                ->cascadeOnDelete();

            $table->foreignId('chapitre_id')
                ->constrained('chapitres')
                ->cascadeOnDelete();

            $table->float('pourcentage')->default(0);
            $table->string('statut');
            $table->dateTime('derniere_consultation')->useCurrent();

            $table->unique(['etudiant_id', 'chapitre_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('progressions');
    }
};
