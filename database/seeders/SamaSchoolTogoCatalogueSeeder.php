<?php

namespace Database\Seeders;

use App\Models\Chapitre;
use App\Models\Matiere;
use App\Models\Niveau;
use App\Models\ParcoursMatiere;
use App\Models\ParcoursScolaire;
use App\Models\Serie;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Seeder idempotent du catalogue préliminaire Togo (relevé public Sama School).
 * Ce jeu de données sert de pré-remplissage de travail (`statut_validation = A_VERIFIER`)
 * et doit être confronté aux maquettes officielles du ministère avant validation définitive.
 */
class SamaSchoolTogoCatalogueSeeder extends Seeder
{
    public const SOURCE_CATALOGUE = 'Sama School — Catalogue public Togo (à vérifier)';
    public const SOURCE_DEMO = 'Contenu de démonstration MVP (ContenuPedagogiqueSeeder)';

    private const MATIERES_DESCRIPTIONS = [
        'Mathématiques' => 'Algèbre, analyse, géométrie, probabilités et statistiques.',
        'Physique' => 'Mécanique, optique, électricité, électromagnétisme, ondes et physique atomique.',
        'Chimie' => 'Chimie organique, chimie des solutions, cinétique et oxydoréduction.',
        'SVT' => 'Sciences de la Vie et de la Terre : biologie cellulaire, génétique, physiologie et géologie.',
        'Français' => 'Étude de la langue, littérature, méthodologie de la dissertation, commentaire et résumé.',
        'Histoire' => 'Histoire du Togo, de l\'Afrique et des relations internationales contemporaines.',
        'Géographie' => 'Démographie, dynamiques urbaines, développement durable et géographie du Togo.',
        'Anglais' => 'Compréhension écrite, expression, grammaire et faits de société en langue anglaise.',
        'ECM' => 'Éducation Civique et Morale : citoyenneté, institutions, droits humains et environnement.',
        'Allemand' => 'Bases lexicales, grammaire allemande et communication autour du quotidien et de la culture.',
    ];

    public function run(): void
    {
        $jsonPath = database_path('data/sama_school_togo_catalogue.json');
        if (! File::exists($jsonPath)) {
            throw new \RuntimeException("Fichier catalogue introuvable : {$jsonPath}");
        }

        $catalogue = json_decode(File::get($jsonPath), true, 512, JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($catalogue) {
            // 1. Marquer proprement les 7 chapitres démo existants (sans rien supprimer ni écraser)
            $demoTitles = [
                'Limites et continuité des fonctions',
                'Nombres complexes',
                'Cinématique et lois de Newton',
                'Génétique et brassage de l\'information génétique',
                'La conscience et l\'inconscient',
                'Les relations internationales de 1945 à 1991 (La Guerre froide)',
                'Science, Technology and Social Change',
            ];

            Chapitre::whereIn('titre', $demoTitles)
                ->where('id', '<=', 7)
                ->update([
                    'source' => self::SOURCE_DEMO,
                    'statut_validation' => Chapitre::STATUT_A_VERIFIER,
                    'est_demo' => true,
                ]);

            // Marquer les anciens parcours_matieres démo/legacy s'ils n'ont pas encore de source
            ParcoursMatiere::whereNull('source')->update([
                'source' => self::SOURCE_DEMO,
                'statut_validation' => ParcoursMatiere::STATUT_A_VERIFIER,
            ]);

            // 2. Harmoniser la série A -> A4 (si présente) et garantir l'existence des 4 séries exactes
            $serieA = Serie::where('code', 'A')->first();
            $serieA4 = Serie::where('code', 'A4')->first();
            if ($serieA && ! $serieA4) {
                $serieA->update([
                    'code' => 'A4',
                    'libelle' => 'Série A4 - Lettres et Sciences Humaines',
                ]);
            } elseif ($serieA && $serieA4 && $serieA->id !== $serieA4->id) {
                $parcoursSurA = ParcoursScolaire::where('serie_id', $serieA->id)->get();
                foreach ($parcoursSurA as $pA) {
                    $pA4Existe = ParcoursScolaire::where('niveau_id', $pA->niveau_id)
                        ->where('serie_id', $serieA4->id)
                        ->first();
                    if (! $pA4Existe) {
                        $pA->update(['serie_id' => $serieA4->id]);
                    }
                }
                if (ParcoursScolaire::where('serie_id', $serieA->id)->count() === 0) {
                    $serieA->delete();
                }
            }

            $seriesByCode = [];
            foreach ($catalogue['series'] as $sData) {
                $seriesByCode[$sData['code']] = Serie::updateOrCreate(
                    ['code' => $sData['code']],
                    ['libelle' => $sData['libelle']]
                );
            }

            // 3. Synchroniser les 3 niveaux
            $niveauxByNom = [];
            foreach ($catalogue['niveaux'] as $nData) {
                $niveauxByNom[$nData['nom']] = Niveau::updateOrCreate(
                    ['nom' => $nData['nom']],
                    ['ordre' => $nData['ordre']]
                );
            }

            // 4. Convertir Seconde C (legacy) -> Seconde S uniquement si aucun Seconde S n'existe déjà
            $seconde = $niveauxByNom['Seconde'];
            if (isset($seriesByCode['C'], $seriesByCode['S'])) {
                $parcoursSecondeS = ParcoursScolaire::where('niveau_id', $seconde->id)
                    ->where('serie_id', $seriesByCode['S']->id)
                    ->first();
                $parcoursSecondeC = ParcoursScolaire::where('niveau_id', $seconde->id)
                    ->where('serie_id', $seriesByCode['C']->id)
                    ->first();

                if ($parcoursSecondeC && ! $parcoursSecondeS) {
                    $parcoursSecondeC->update([
                        'serie_id' => $seriesByCode['S']->id,
                    ]);
                }
            }

            // 5. Importer les 8 parcours exacts, les 10 matières distinctes, les 47 associations et les 484 chapitres
            $sourceLabel = $catalogue['meta']['source_label'] ?? self::SOURCE_CATALOGUE;
            $defaultStatut = $catalogue['meta']['statut_validation_defaut'] ?? Chapitre::STATUT_A_VERIFIER;

            foreach ($catalogue['parcours'] as $pData) {
                $niveau = $niveauxByNom[$pData['niveau']];
                $serie = $seriesByCode[$pData['serie']];

                $parcours = ParcoursScolaire::firstOrCreate([
                    'niveau_id' => $niveau->id,
                    'serie_id' => $serie->id,
                ]);

                foreach ($pData['matieres'] as $mData) {
                    $matiereNom = $mData['nom'];
                    $matiere = Matiere::firstOrCreate(
                        ['nom' => $matiereNom],
                        ['description' => self::MATIERES_DESCRIPTIONS[$matiereNom] ?? "Programme de {$matiereNom}."]
                    );

                    $parcoursMatiere = ParcoursMatiere::updateOrCreate(
                        [
                            'parcours_id' => $parcours->id,
                            'matiere_id' => $matiere->id,
                        ],
                        [
                            'ordre' => (int) $mData['ordre'],
                            'source' => $sourceLabel,
                            'statut_validation' => $defaultStatut,
                        ]
                    );

                    foreach ($mData['chapitres'] as $chData) {
                        Chapitre::updateOrCreate(
                            [
                                'parcours_matiere_id' => $parcoursMatiere->id,
                                'titre' => $chData['titre'],
                                'est_demo' => false,
                            ],
                            [
                                'ordre' => (int) $chData['ordre'],
                                'source' => $sourceLabel,
                                'statut_validation' => $defaultStatut,
                            ]
                        );
                    }
                }
            }
        });
    }
}
