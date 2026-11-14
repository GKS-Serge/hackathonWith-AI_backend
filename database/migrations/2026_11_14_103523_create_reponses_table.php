<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reponses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tentative_id')
                ->constrained('tentatives')
                ->cascadeOnDelete();

            $table->foreignId('question_id')
                ->constrained('questions')
                ->cascadeOnDelete();

            $table->foreignId('proposition_id')
                ->nullable()
                ->constrained('propositions')
                ->nullOnDelete();

            $table->text('contenu')->nullable();
            $table->boolean('est_correcte')->default(false);
            $table->float('points_obtenus')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reponses');
    }
};
