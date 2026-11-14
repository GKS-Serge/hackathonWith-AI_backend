<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tentatives', function (Blueprint $table) {
            $table->id();

            $table->foreignId('etudiant_id')
                ->constrained('etudiants')
                ->cascadeOnDelete();

            $table->foreignId('quiz_id')
                ->constrained('quizzes')
                ->cascadeOnDelete();

            $table->dateTime('date_passage')->useCurrent();
            $table->float('note')->nullable();
            $table->string('statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tentatives');
    }
};
