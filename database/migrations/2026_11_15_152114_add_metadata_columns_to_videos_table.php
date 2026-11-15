<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->text('description')->nullable()->after('titre');
            $table->string('duree')->nullable()->after('youtube_id'); // Format ISO 8601 (PT15M33S) ou format lisible (15:33)
            $table->integer('duree_secondes')->nullable()->after('duree');
            $table->string('thumbnail_url')->nullable()->after('duree_secondes');
            $table->string('chaine_titre')->nullable()->after('thumbnail_url');
            $table->boolean('est_integrable')->default(true)->after('chaine_titre');
            $table->string('statut_integration')->default('VALIDATED')->after('est_integrable'); // VALIDATED, PENDING, REJECTED
            $table->timestamp('published_at')->nullable()->after('statut_integration');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'duree',
                'duree_secondes',
                'thumbnail_url',
                'chaine_titre',
                'est_integrable',
                'statut_integration',
                'published_at',
                'created_at',
                'updated_at',
            ]);
        });
    }
};
