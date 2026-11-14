<?php

namespace Database\Seeders;

use App\Models\Etudiant;
use App\Models\Niveau;
use App\Models\ParcoursScolaire;
use App\Models\Serie;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EtudiantSeeder extends Seeder
{
    public function run(): void
    {
        $terminale = Niveau::where('nom', 'Terminale')->firstOrFail();
        $serieC = Serie::where('code', 'C')->firstOrFail();

        $parcoursTerminaleC = ParcoursScolaire::where('niveau_id', $terminale->id)
            ->where('serie_id', $serieC->id)
            ->firstOrFail();

        Etudiant::updateOrCreate(
            ['email' => 'etudiant@scholarship.test'],
            [
                'nom' => 'Kouassi',
                'prenom' => 'Aya',
                'mot_de_passe_hash' => Hash::make('Password123!'),
                'parcours_id' => $parcoursTerminaleC->id,
                'date_inscription' => now(),
            ]
        );
    }
}
