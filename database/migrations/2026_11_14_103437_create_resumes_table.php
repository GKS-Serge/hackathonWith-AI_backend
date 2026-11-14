<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('resumes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cours_id')
                ->nullable()
                ->constrained('cours')
                ->cascadeOnDelete();

            $table->foreignId('transcription_id')
                ->nullable()
                ->constrained('transcriptions')
                ->cascadeOnDelete();

            $table->text('contenu');
            $table->dateTime('date_creation')->useCurrent();
        });

        DB::statement('ALTER TABLE resumes ADD CONSTRAINT resumes_single_source_check CHECK ((cours_id IS NOT NULL AND transcription_id IS NULL) OR (cours_id IS NULL AND transcription_id IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('resumes');
    }
};
