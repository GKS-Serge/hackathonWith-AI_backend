<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapitre;
use App\Models\Cours;
use App\Models\Matiere;
use App\Models\Niveau;
use App\Models\ParcoursMatiere;
use App\Models\ParcoursScolaire;
use App\Models\Progression;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Resume;
use App\Models\Serie;
use App\Models\Tentative;
use App\Models\Transcription;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferentielController extends Controller
{
    /**
     * Liste tous les niveaux scolaires par ordre croissant.
     */
    public function niveaux(): JsonResponse
    {
        $niveaux = Niveau::orderBy('ordre')->get();

        return response()->json([
            'niveaux' => $niveaux,
        ]);
    }

    /**
     * Liste toutes les séries par code croissant.
     */
    public function series(): JsonResponse
    {
        $series = Serie::orderBy('code')->get();

        return response()->json([
            'series' => $series,
        ]);
    }

    /**
     * Liste uniquement les séries réellement disponibles pour un niveau donné,
     * avec le `parcours_id` correspondant à chaque couple (niveau, série).
     */
    public function seriesByNiveau(Niveau $niveau): JsonResponse
    {
        $parcoursList = ParcoursScolaire::with('serie')
            ->where('niveau_id', $niveau->id)
            ->get()
            ->sortBy(fn (ParcoursScolaire $p) => $p->serie->code)
            ->values();

        $series = $parcoursList->map(function (ParcoursScolaire $p) {
            return [
                'parcours_id' => $p->id,
                'serie' => $p->serie,
            ];
        });

        return response()->json([
            'niveau' => $niveau,
            'series_disponibles' => $series,
        ]);
    }

    /**
     * Liste les parcours scolaires avec leur niveau et leur série.
     */
    public function parcours(Request $request): JsonResponse
    {
        $query = ParcoursScolaire::with(['niveau', 'serie']);

        if ($request->filled('niveau_id')) {
            $query->where('niveau_id', (int) $request->query('niveau_id'));
        }

        if ($request->filled('serie_id')) {
            $query->where('serie_id', (int) $request->query('serie_id'));
        }

        return response()->json([
            'parcours_scolaires' => $query->get(),
        ]);
    }

    /**
     * Liste toutes les matières disponibles.
     */
    public function matieres(): JsonResponse
    {
        $matieres = Matiere::orderBy('nom')->get();

        return response()->json([
            'matieres' => $matieres,
        ]);
    }

    /**
     * Retourne les matières automatiquement affectées à l'étudiant connecté selon son parcours,
     * enrichies du nombre de chapitres, de sa progression et de sa moyenne aux quiz dans chaque matière.
     */
    public function mesMatieres(Request $request): JsonResponse
    {
        $etudiant = $request->user();
        $etudiant->load(['parcoursScolaire.niveau', 'parcoursScolaire.serie']);

        $parcoursMatieres = ParcoursMatiere::with(['matiere', 'chapitres.quizzes'])
            ->where('parcours_id', $etudiant->parcours_id)
            ->orderBy('ordre')
            ->get();

        $progressionsByChapitre = Progression::where('etudiant_id', $etudiant->id)
            ->get()
            ->keyBy('chapitre_id');

        $tentativesEtudiant = Tentative::where('etudiant_id', $etudiant->id)
            ->where('statut', Tentative::STATUT_TERMINEE)
            ->get()
            ->groupBy('quiz_id');

        $matieres = $parcoursMatieres->map(function (ParcoursMatiere $pm) use ($progressionsByChapitre, $tentativesEtudiant) {
            $chapitres = $pm->chapitres;
            $chapitresCount = $chapitres->count();
            $terminesCount = 0;
            $enCoursCount = 0;
            $sommePourcentages = 0.0;
            $notesMatiere = [];

            foreach ($chapitres as $chapitre) {
                $prog = $progressionsByChapitre->get($chapitre->id);
                if ($prog) {
                    $sommePourcentages += (float) $prog->pourcentage;
                    if ($prog->statut === Progression::STATUT_TERMINE) {
                        $terminesCount++;
                    } elseif ($prog->statut === Progression::STATUT_EN_COURS) {
                        $enCoursCount++;
                    }
                }

                foreach ($chapitre->quizzes as $quiz) {
                    $tentativesQuiz = $tentativesEtudiant->get($quiz->id);
                    if ($tentativesQuiz && $tentativesQuiz->isNotEmpty()) {
                        foreach ($tentativesQuiz as $t) {
                            if ($t->note !== null) {
                                $notesMatiere[] = (float) $t->note;
                            }
                        }
                    }
                }
            }

            $pourcentageMatiere = $chapitresCount > 0
                ? round($sommePourcentages / $chapitresCount, 2)
                : 0.0;

            return [
                'parcours_matiere_id' => $pm->id,
                'ordre' => $pm->ordre,
                'source' => $pm->source,
                'statut_validation' => $pm->statut_validation,
                'matiere' => $pm->matiere,
                'chapitres_count' => $chapitresCount,
                'chapitres_termines_count' => $terminesCount,
                'chapitres_en_cours_count' => $enCoursCount,
                'progression_pourcentage' => $pourcentageMatiere,
                'statut_progression' => Progression::resolveStatutFromPourcentage($pourcentageMatiere),
                'moyenne_quiz' => ! empty($notesMatiere)
                    ? round(array_sum($notesMatiere) / count($notesMatiere), 2)
                    : null,
            ];
        });

        return response()->json([
            'parcours_scolaire' => $etudiant->parcoursScolaire,
            'matieres' => $matieres,
        ]);
    }

    /**
     * Télécharge le document source Word conservé dans l'espace privé backend pour l'équipe technique.
     */
    public function downloadDocumentSource(): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        $path = storage_path('app/private/referentiels/Academie_referentiel_programmes_Togo_backend.docx');

        if (! file_exists($path)) {
            return response()->json([
                'message' => 'Le document de référence est introuvable dans l\'espace privé.',
            ], 404);
        }

        return response()->download($path, 'Academie_referentiel_programmes_Togo_backend.docx');
    }

    /**
     * Liste les matières associées à un parcours scolaire donné, ordonnées selon le programme.
     */
    public function matieresByParcours(Request $request, ParcoursScolaire $parcours): JsonResponse
    {
        $parcours->load(['niveau', 'serie']);
        $inclureDemo = $request->boolean('inclure_demo', true);
        $sourceFilter = $request->query('source');

        $query = ParcoursMatiere::with('matiere')
            ->withCount(['chapitres' => function ($q) use ($inclureDemo) {
                if (! $inclureDemo) {
                    $q->where('est_demo', false);
                }
            }])
            ->where('parcours_id', $parcours->id);

        if ($sourceFilter === 'catalogue') {
            $query->where('source', 'like', 'Sama School%');
        } elseif (! $inclureDemo) {
            $query->where('source', 'not like', 'Contenu de démonstration%');
        }

        $parcoursMatieres = $query->orderBy('ordre')
            ->get()
            ->map(function (ParcoursMatiere $pm) {
                return [
                    'parcours_matiere_id' => $pm->id,
                    'parcours_id' => $pm->parcours_id,
                    'ordre' => $pm->ordre,
                    'source' => $pm->source,
                    'statut_validation' => $pm->statut_validation,
                    'chapitres_count' => $pm->chapitres_count,
                    'matiere' => $pm->matiere,
                ];
            });

        return response()->json([
            'parcours_scolaire' => $parcours,
            'matieres' => $parcoursMatieres,
        ]);
    }

    /**
     * Liste les chapitres d'une matière avec la progression de l'étudiant connecté sur chaque chapitre.
     */
    public function chapitresByMatiere(Request $request, Matiere $matiere): JsonResponse
    {
        $etudiant = $request->user();
        $parcoursId = $request->filled('parcours_id')
            ? (int) $request->query('parcours_id')
            : (int) $etudiant->parcours_id;
        $inclureDemo = $request->boolean('inclure_demo', true);

        $parcoursMatiere = ParcoursMatiere::with(['parcoursScolaire.niveau', 'parcoursScolaire.serie', 'matiere'])
            ->where('parcours_id', $parcoursId)
            ->where('matiere_id', $matiere->id)
            ->first();

        if (! $parcoursMatiere) {
            return response()->json([
                'message' => "Cette matière n'est pas associée au parcours scolaire sélectionné.",
            ], 404);
        }

        $chapitres = $this->getChapitresWithStudentProgression($parcoursMatiere->id, $etudiant->id, $inclureDemo);

        return response()->json([
            'parcours_matiere_id' => $parcoursMatiere->id,
            'parcours_scolaire' => $parcoursMatiere->parcoursScolaire,
            'matiere' => $matiere,
            'source' => $parcoursMatiere->source,
            'statut_validation' => $parcoursMatiere->statut_validation,
            'chapitres' => $chapitres,
        ]);
    }

    /**
     * Liste les chapitres directement à partir d'un ParcoursMatiere avec la progression de l'étudiant.
     */
    public function chapitresByParcoursMatiere(Request $request, ParcoursMatiere $parcoursMatiere): JsonResponse
    {
        $etudiant = $request->user();
        $parcoursMatiere->load(['parcoursScolaire.niveau', 'parcoursScolaire.serie', 'matiere']);
        $inclureDemo = $request->boolean('inclure_demo', true);

        $chapitres = $this->getChapitresWithStudentProgression($parcoursMatiere->id, $etudiant->id, $inclureDemo);

        return response()->json([
            'parcours_matiere' => $parcoursMatiere,
            'chapitres' => $chapitres,
        ]);
    }

    /**
     * Affiche le module complet d'un chapitre : métadonnées, compteurs, contenus (cours, vidéos,
     * ressources, quiz sans réponses, résumés) et progression de l'étudiant connecté.
     */
    public function showChapitre(Request $request, Chapitre $chapitre): JsonResponse
    {
        $etudiant = $request->user();

        $chapitre->load([
            'parcoursMatiere.matiere',
            'parcoursMatiere.parcoursScolaire.niveau',
            'parcoursMatiere.parcoursScolaire.serie',
            'cours.resumes',
            'videos.transcription.resumes',
            'ressources',
            'quizzes' => function ($query) {
                $query->withCount('questions')
                    ->withSum('questions as total_points', 'points');
            },
        ])->loadCount(['cours', 'videos', 'ressources', 'quizzes']);

        $progression = Progression::where('etudiant_id', $etudiant->id)
            ->where('chapitre_id', $chapitre->id)
            ->first();

        $coursIds = $chapitre->cours->pluck('id');
        $transcriptionIds = $chapitre->videos->pluck('transcription.id')->filter();

        $resumesChapitre = Resume::where(function ($q) use ($coursIds, $transcriptionIds) {
            $q->whereIn('cours_id', $coursIds)
                ->orWhereIn('transcription_id', $transcriptionIds);
        })->orderByDesc('date_creation')->get();

        return response()->json([
            'chapitre' => $chapitre,
            'resumes' => $resumesChapitre,
            'progression' => $progression ?? [
                'etudiant_id' => $etudiant->id,
                'chapitre_id' => $chapitre->id,
                'pourcentage' => 0.0,
                'statut' => Progression::STATUT_NON_COMMENCE,
                'derniere_consultation' => null,
            ],
        ]);
    }

    /**
     * Récupère les chapitres d'un ParcoursMatiere enrichis de la progression de l'étudiant.
     */
    private function getChapitresWithStudentProgression(int $parcoursMatiereId, int $etudiantId, bool $inclureDemo = true)
    {
        $query = Chapitre::where('parcours_matiere_id', $parcoursMatiereId)
            ->withCount(['cours', 'videos', 'ressources', 'quizzes'])
            ->orderBy('ordre')
            ->orderBy('id');

        if (! $inclureDemo) {
            $query->where('est_demo', false);
        }

        $chapitres = $query->get();

        $progressions = Progression::where('etudiant_id', $etudiantId)
            ->whereIn('chapitre_id', $chapitres->pluck('id'))
            ->get()
            ->keyBy('chapitre_id');

        return $chapitres->map(function (Chapitre $chapitre) use ($progressions, $etudiantId) {
            $prog = $progressions->get($chapitre->id);
            $chapitreArray = $chapitre->toArray();
            $chapitreArray['progression'] = $prog ? $prog->toArray() : [
                'etudiant_id' => $etudiantId,
                'chapitre_id' => $chapitre->id,
                'pourcentage' => 0.0,
                'statut' => Progression::STATUT_NON_COMMENCE,
                'derniere_consultation' => null,
            ];
            return $chapitreArray;
        });
    }
}
