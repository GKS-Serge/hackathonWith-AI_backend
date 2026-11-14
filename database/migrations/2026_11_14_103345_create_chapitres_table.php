<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chapitres', function (Blueprint $table) {
            $table->id();

            $table->foreignId('parcours_matiere_id')
                ->constrained('parcours_matieres')
                ->cascadeOnDelete();

            $table->string('titre');
            $table->text('description')->nullable();
            $table->integer('ordre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapitres');
    }
};
