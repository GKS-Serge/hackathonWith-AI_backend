<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('propositions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('question_id')
                ->constrained('questions')
                ->cascadeOnDelete();

            $table->text('contenu');
            $table->boolean('est_correcte');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('propositions');
    }
};
