<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute les métadonnées de traçabilité (source, statut de validation et flag démo)
     * aux tables `parcours_matieres` et `chapitres`, de manière strictement additive et non destructive.
     */
    public function up(): void
    {
        Schema::table('parcours_matieres', function (Blueprint $table) {
            $table->string('source')->nullable()->after('ordre');
            $table->string('statut_validation', 30)->default('A_VERIFIER')->after('source');
        });

        Schema::table('chapitres', function (Blueprint $table) {
            $table->string('source')->nullable()->after('ordre');
            $table->string('statut_validation', 30)->default('A_VERIFIER')->after('source');
            $table->boolean('est_demo')->default(false)->after('statut_validation');
        });
    }

    /**
     * Annule uniquement les colonnes ajoutées par cette migration.
     */
    public function down(): void
    {
        Schema::table('chapitres', function (Blueprint $table) {
            $table->dropColumn(['source', 'statut_validation', 'est_demo']);
        });

        Schema::table('parcours_matieres', function (Blueprint $table) {
            $table->dropColumn(['source', 'statut_validation']);
        });
    }
};
