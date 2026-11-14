<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transcriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('video_id')
                ->unique()
                ->constrained('videos')
                ->cascadeOnDelete();

            $table->text('contenu');
            $table->dateTime('date_creation')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcriptions');
    }
};
