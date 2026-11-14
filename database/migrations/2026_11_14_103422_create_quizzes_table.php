<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('chapitre_id')
                ->constrained('chapitres')
                ->cascadeOnDelete();

            $table->string('titre');
            $table->text('description')->nullable();
            $table->string('type');
            $table->string('origine');
            $table->dateTime('date_creation')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};
