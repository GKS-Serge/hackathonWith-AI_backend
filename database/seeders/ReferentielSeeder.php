<?php

namespace Database\Seeders;

use App\Models\Matiere;
use App\Models\Niveau;
use App\Models\ParcoursMatiere;
use App\Models\ParcoursScolaire;
use App\Models\Serie;
use Illuminate\Database\Seeder;

class ReferentielSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Niveaux
        $niveauxData = [
            ['nom' => 'Seconde', 'ordre' => 1],
            ['nom' => 'Première', 'ordre' => 2],
            ['nom' => 'Terminale', 'ordre' => 3],
        ];

        $niveaux = [];
        foreach ($niveauxData as $data) {
            $niveaux[$data['nom']] = Niveau::updateOrCreate(
                ['nom' => $data['nom']],
                ['ordre' => $data['ordre']]
            );
        }

        // 2. Séries
        $seriesData = [
            ['code' => 'A4', 'libelle' => 'Série A4 - Lettres et Sciences Humaines'],
            ['code' => 'S', 'libelle' => 'Série S - Scientifique (Seconde)'],
            ['code' => 'C', 'libelle' => 'Série C - Mathématiques et Sciences Physiques'],
            ['code' => 'D', 'libelle' => 'Série D - Sciences de la Vie et de la Terre'],
        ];

        $series = [];
        foreach ($seriesData as $data) {
            if ($data['code'] === 'A4') {
                $existingA = Serie::where('code', 'A')->first();
                if ($existingA && ! Serie::where('code', 'A4')->exists()) {
                    $existingA->update(['code' => 'A4', 'libelle' => $data['libelle']]);
                }
            }
            $series[$data['code']] = Serie::updateOrCreate(
                ['code' => $data['code']],
                ['libelle' => $data['libelle']]
            );
        }

        // 3. Matières
        $matieresData = [
            'Mathématiques' => 'Algèbre, analyse, géométrie et probabilités.',
            'Physique-Chimie' => 'Mécanique, électromagnétisme, thermodynamique et chimie générale/organique.',
            'SVT' => 'Sciences de la Vie et de la Terre : biologie cellulaire, génétique et géologie.',
            'Français' => 'Étude de la langue, littérature, méthodologie de la dissertation et du commentaire.',
            'Philosophie' => 'Réflexion critique sur la conscience, la science, la morale et la politique.',
            'Histoire-Géographie' => 'Histoire contemporaine, relations internationales et géographie économique.',
            'Anglais' => 'Compréhension écrite et orale, grammaire et expression en langue anglaise.',
        ];

        $matieres = [];
        foreach ($matieresData as $nom => $description) {
            $matieres[$nom] = Matiere::updateOrCreate(
                ['nom' => $nom],
                ['description' => $description]
            );
        }

        // 4. Parcours scolaires et Parcours matières
        $parcoursDefinitions = [
            ['niveau' => 'Terminale', 'serie' => 'C', 'matieres' => ['Mathématiques', 'Physique-Chimie', 'SVT', 'Philosophie', 'Histoire-Géographie', 'Anglais']],
            ['niveau' => 'Terminale', 'serie' => 'D', 'matieres' => ['SVT', 'Physique-Chimie', 'Mathématiques', 'Philosophie', 'Histoire-Géographie', 'Anglais']],
            ['niveau' => 'Terminale', 'serie' => 'A4', 'matieres' => ['Philosophie', 'Français', 'Histoire-Géographie', 'Anglais', 'Mathématiques']],
            ['niveau' => 'Première', 'serie' => 'C', 'matieres' => ['Mathématiques', 'Physique-Chimie', 'SVT', 'Français', 'Histoire-Géographie', 'Anglais']],
            ['niveau' => 'Première', 'serie' => 'D', 'matieres' => ['SVT', 'Physique-Chimie', 'Mathématiques', 'Français', 'Histoire-Géographie', 'Anglais']],
            ['niveau' => 'Première', 'serie' => 'A4', 'matieres' => ['Français', 'Histoire-Géographie', 'Anglais', 'Mathématiques']],
            ['niveau' => 'Seconde', 'serie' => 'S', 'matieres' => ['Mathématiques', 'Physique-Chimie', 'SVT', 'Français', 'Histoire-Géographie', 'Anglais']],
            ['niveau' => 'Seconde', 'serie' => 'A4', 'matieres' => ['Français', 'Histoire-Géographie', 'Anglais', 'Mathématiques']],
        ];

        foreach ($parcoursDefinitions as $def) {
            $parcours = ParcoursScolaire::firstOrCreate([
                'niveau_id' => $niveaux[$def['niveau']]->id,
                'serie_id' => $series[$def['serie']]->id,
            ]);

            foreach ($def['matieres'] as $index => $matiereNom) {
                ParcoursMatiere::updateOrCreate(
                    [
                        'parcours_id' => $parcours->id,
                        'matiere_id' => $matieres[$matiereNom]->id,
                    ],
                    [
                        'ordre' => $index + 1,
                    ]
                );
            }
        }
    }
}
