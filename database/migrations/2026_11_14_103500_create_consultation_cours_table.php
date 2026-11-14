<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('consultation_cours', function (Blueprint $table) {
            $table->id();

            $table->foreignId('etudiant_id')
                ->constrained('etudiants')
                ->cascadeOnDelete();

            $table->foreignId('cours_id')
                ->constrained('cours')
                ->cascadeOnDelete();

            $table->dateTime('date_consultation')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_cours');
    }
};
