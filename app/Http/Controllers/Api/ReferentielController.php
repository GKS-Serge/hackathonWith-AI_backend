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
     * Récupère la vue complète et synthétique d'un chapitre pour l'étudiant connecté (PHASE 2).
     * Regroupe : métadonnées du chapitre, cours, vidéos intégrables, quiz disponibles,
     * ressources complémentaires et progression personnalisée de l'étudiant.
     */
    public function showChapitreComplet(Request $request, int $id): JsonResponse
    {
        $etudiant = $request->user();

        // 1. Recherche du chapitre avec ses relations structurelles
        $chapitre = Chapitre::with([
            'parcoursMatiere.parcours.niveau',
            'parcoursMatiere.parcours.serie',
            'parcoursMatiere.matiere',
            'cours.resumes',
            'videos.transcription',
            'ressources',
            'quizzes.questions',
        ])->find($id);

        if (! $chapitre) {
            return response()->json([
                'message' => 'Chapitre introuvable.',
                'code' => 'CHAPITRE_NOT_FOUND',
            ], 404);
        }

        // 2. Vérification stricte des permissions de parcours scolaire (403 Forbidden)
        if ($etudiant && $etudiant->parcours_id) {
            $parcoursChapitreId = $chapitre->parcoursMatiere?->parcours_id;
            if ($parcoursChapitreId && $parcoursChapitreId !== $etudiant->parcours_id) {
                return response()->json([
                    'message' => 'Ce chapitre ne fait pas partie de votre parcours scolaire.',
                    'code' => 'PARCOURS_ACCESS_DENIED',
                ], 403);
            }
        }

        // 3. Récupération de la progression de l'étudiant connecté pour ce chapitre (strictement personnelle)
        $progression = Progression::where('etudiant_id', $etudiant->id)
            ->where('chapitre_id', $chapitre->id)
            ->first();

        // 4. Consultation des cours pour cet étudiant (pour marquer déjà consulté)
        $consultationsCours = \App\Models\ConsultationCours::where('etudiant_id', $etudiant->id)
            ->whereIn('cours_id', $chapitre->cours->pluck('id'))
            ->get()
            ->keyBy('cours_id');

        // 5. Tentatives de quiz pour cet étudiant
        $tentatives = Tentative::where('etudiant_id', $etudiant->id)
            ->whereIn('quiz_id', $chapitre->quizzes->pluck('id'))
            ->get()
            ->groupBy('quiz_id');

        // Formatage Cours
        $formattedCours = $chapitre->cours->map(function ($c) use ($consultationsCours) {
            $consultation = $consultationsCours->get($c->id);
            return [
                'id' => $c->id,
                'titre' => $c->titre,
                'resume' => $c->resumes->first()?->contenu ?? null,
                'contenu' => $c->contenu,
                'temps_lecture_estime' => max(5, (int) ceil(str_word_count(strip_tags((string) $c->contenu)) / 200)),
                'deja_consulte' => $consultation !== null,
                'derniere_consultation_a' => $consultation?->date_consultation?->toIso8601String(),
                'date_creation' => $c->date_creation?->toIso8601String(),
            ];
        })->values();

        // Formatage Vidéos (avec a_transcription et statut conforme)
        $formattedVideos = $chapitre->videos
            ->where('est_integrable', true)
            ->map(function ($v) {
                $hasTranscription = $v->transcription !== null && !empty($v->transcription->contenu);
                return [
                    'id' => $v->id,
                    'titre' => $v->titre,
                    'description' => $v->description,
                    'youtube_id' => $v->youtube_id,
                    'duree' => $v->duree ?? '00:00',
                    'duree_secondes' => $v->duree_secondes ?? 0,
                    'thumbnail_url' => $v->thumbnail_url,
                    'embed_url' => $v->embed_url,
                    'chaine_titre' => $v->chaine_titre,
                    'a_transcription' => $hasTranscription,
                    'statut_transcription' => $hasTranscription ? 'DISPONIBLE' : 'NON_DISPONIBLE_POUR_LE_MOMENT',
                ];
            })->values();

        // Formatage Quiz
        $formattedQuizzes = $chapitre->quizzes->map(function ($q) use ($tentatives) {
            $quizTentatives = $tentatives->get($q->id, collect());
            $meilleureNote = $quizTentatives->max('note');
            $hasSuccess = $quizTentatives->where('note', '>=', 60.0)->isNotEmpty();

            return [
                'id' => $q->id,
                'titre' => $q->titre,
                'description' => $q->description,
                'type' => $q->type,
                'nombre_questions' => $q->questions->count(),
                'tentatives_effectuees' => $quizTentatives->count(),
                'meilleur_score' => $meilleureNote !== null ? (float) $meilleureNote : null,
                'statut' => $quizTentatives->isEmpty()
                    ? 'NON_COMMENCE'
                    : ($hasSuccess ? 'REUSSI' : 'A_REVOIR'),
            ];
        })->values();

        // Formatage Ressources Complémentaires (PDF)
        $formattedRessources = $chapitre->ressources->map(function ($r) {
            return [
                'id' => $r->id,
                'titre' => $r->titre,
                'type' => $r->type ?? 'DOCUMENT',
                'url' => $r->url,
                'disponible' => !empty($r->url),
            ];
        })->values();

        // Formatage Réponse Globale
        return response()->json([
            'chapitre' => [
                'id' => $chapitre->id,
                'titre' => $chapitre->titre,
                'description' => $chapitre->description,
                'position' => $chapitre->ordre,
                'statut_validation' => $chapitre->statut_validation,
                'est_demo' => (bool) $chapitre->est_demo,
                'matiere' => [
                    'id' => $chapitre->parcoursMatiere?->matiere?->id,
                    'nom' => $chapitre->parcoursMatiere?->matiere?->nom,
                    'code' => $chapitre->parcoursMatiere?->matiere?->code,
                ],
                'parcours' => [
                    'id' => $chapitre->parcoursMatiere?->parcours?->id,
                    'nom' => $chapitre->parcoursMatiere?->parcours?->nom,
                    'niveau' => $chapitre->parcoursMatiere?->parcours?->niveau?->nom,
                    'serie' => $chapitre->parcoursMatiere?->parcours?->serie?->nom,
                ],
            ],
            'progression' => [
                'etudiant_id' => $etudiant->id,
                'chapitre_id' => $chapitre->id,
                'pourcentage' => $progression ? (float) $progression->pourcentage : 0.0,
                'statut' => $progression ? $progression->statut : Progression::STATUT_NON_COMMENCE,
                'cours_consulte' => $consultationsCours->isNotEmpty(),
                'quiz_reussi' => $formattedQuizzes->where('statut', 'REUSSI')->isNotEmpty(),
                'derniere_activite_a' => $progression?->derniere_consultation?->toIso8601String(),
            ],
            'cours' => $formattedCours,
            'videos' => $formattedVideos,
            'quizzes' => $formattedQuizzes,
            'ressources' => $formattedRessources,
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
