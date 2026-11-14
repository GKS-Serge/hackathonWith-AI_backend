<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapitre;
use App\Models\Progression;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Reponse;
use App\Models\Tentative;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuizController extends Controller
{
    /**
     * Liste et filtre les quiz/exercices/révisions (par `type`, `origine`, `chapitre_id`, `matiere_id` ou mot-clé `q`).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Quiz::with('chapitre.parcoursMatiere.matiere')
            ->withCount('questions')
            ->withSum('questions as total_points', 'points');

        $this->applyQuizFilters($query, $request);

        return response()->json([
            'quizzes' => $query->orderBy('id')->get(),
        ]);
    }

    /**
     * Liste les quiz disponibles pour un chapitre donné (filtrables par `type`, `origine`, `q`).
     */
    public function indexByChapitre(Request $request, Chapitre $chapitre): JsonResponse
    {
        $query = Quiz::where('chapitre_id', $chapitre->id)
            ->withCount('questions')
            ->withSum('questions as total_points', 'points');

        $this->applyQuizFilters($query, $request);

        return response()->json([
            'chapitre_id' => $chapitre->id,
            'quizzes' => $query->orderBy('id')->get(),
        ]);
    }

    /**
     * Affiche un quiz avec ses questions et propositions, SANS exposer les bonnes réponses ni les explications.
     */
    public function show(Quiz $quiz): JsonResponse
    {
        $quiz->load(['chapitre', 'questions.propositions']);

        $this->hideAnswersFromQuiz($quiz);

        $totalPoints = (float) $quiz->questions->sum('points');

        return response()->json([
            'quiz' => $quiz,
            'total_points' => $totalPoints,
        ]);
    }

    /**
     * Démarre une nouvelle tentative sur un quiz pour l'étudiant connecté.
     */
    public function startTentative(Request $request, Quiz $quiz): JsonResponse
    {
        $etudiant = $request->user();

        $tentative = Tentative::create([
            'etudiant_id' => $etudiant->id,
            'quiz_id' => $quiz->id,
            'date_passage' => now(),
            'note' => null,
            'statut' => Tentative::STATUT_EN_COURS,
        ]);

        $quiz->load('questions.propositions');
        $this->hideAnswersFromQuiz($quiz);

        return response()->json([
            'message' => 'Tentative démarrée avec succès.',
            'tentative' => $tentative,
            'quiz' => $quiz,
        ], 201);
    }

    /**
     * Soumet les réponses d'une tentative :
     * - Exige obligatoirement un `proposition_id` valide pour toute question QCM.
     * - Enregistre les réponses textuelles des questions ouvertes pour une future évaluation Mistral
     *   sans attribuer arbitrairement une note définitive (statut `EN_ATTENTE_CORRECTION`).
     * - Recalcule automatiquement la progression du chapitre.
     */
    public function submitTentative(Request $request, Tentative $tentative): JsonResponse
    {
        $etudiant = $request->user();

        if ((int) $tentative->etudiant_id !== (int) $etudiant->id) {
            return response()->json([
                'message' => 'Accès interdit : cette tentative ne vous appartient pas.',
            ], 403);
        }

        $validated = $request->validate([
            'reponses' => ['required', 'array', 'min:1'],
            'reponses.*.question_id' => ['required', 'integer', 'distinct'],
            'reponses.*.proposition_id' => ['nullable', 'integer'],
            'reponses.*.contenu' => ['nullable', 'string'],
        ], [
            'reponses.*.question_id.distinct' => 'Une même question ne peut pas recevoir plusieurs réponses dans une tentative.',
        ]);

        $result = DB::transaction(function () use ($tentative, $etudiant, $validated) {
            // Verrouillage pessimiste (SELECT ... FOR UPDATE) contre les soumissions simultanées
            $lockedTentative = Tentative::with('quiz')
                ->where('id', $tentative->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedTentative->etudiant_id !== (int) $etudiant->id) {
                abort(403, 'Accès interdit : cette tentative ne vous appartient pas.');
            }

            if ($lockedTentative->statut !== Tentative::STATUT_EN_COURS) {
                throw ValidationException::withMessages([
                    'tentative' => ['Cette tentative est déjà terminée et ne peut plus être modifiée.'],
                ]);
            }

            $questions = Question::with('propositions')
                ->where('quiz_id', $lockedTentative->quiz_id)
                ->get()
                ->keyBy('id');

            $totalPointsPossibles = max(0.0, (float) $questions->sum('points'));

            $preparedReponses = [];
            $seenQuestionIds = [];
            $evaluationIaRequise = false;
            $sommePointsObtenus = 0.0;

            foreach ($validated['reponses'] as $index => $repData) {
                $questionId = (int) $repData['question_id'];

                if (isset($seenQuestionIds[$questionId])) {
                    throw ValidationException::withMessages([
                        "reponses.$index.question_id" => ["Doublon détecté pour la question ID {$questionId}."],
                    ]);
                }
                $seenQuestionIds[$questionId] = true;

                /** @var Question|null $question */
                $question = $questions->get($questionId);
                if (! $question) {
                    throw ValidationException::withMessages([
                        "reponses.$index.question_id" => ["La question ID {$questionId} n'appartient pas au quiz de cette tentative."],
                    ]);
                }

                $isQcm = strtoupper((string) $question->type) === 'QCM' || $question->propositions->isNotEmpty();
                $propositionId = $repData['proposition_id'] ?? null;
                $contenuTexte = isset($repData['contenu']) ? trim((string) $repData['contenu']) : null;
                $pointsMaxQuestion = max(0.0, (float) $question->points);

                if ($isQcm) {
                    // Règle métier : pour une question QCM, une proposition_id valide est strictement obligatoire
                    if ($propositionId === null) {
                        throw ValidationException::withMessages([
                            "reponses.$index.proposition_id" => ["La question ID {$questionId} est un QCM : vous devez obligatoirement sélectionner une proposition (proposition_id)."],
                        ]);
                    }

                    $proposition = $question->propositions->firstWhere('id', (int) $propositionId);
                    if (! $proposition) {
                        throw ValidationException::withMessages([
                            "reponses.$index.proposition_id" => ["La proposition ID {$propositionId} n'appartient pas à la question ID {$questionId}."],
                        ]);
                    }

                    $estCorrecte = (bool) $proposition->est_correcte;
                    $pointsObtenus = $estCorrecte ? $pointsMaxQuestion : 0.0;
                    $pointsObtenus = min($pointsObtenus, $pointsMaxQuestion);
                    $contenuFinal = ($contenuTexte !== null && $contenuTexte !== '') ? $contenuTexte : $proposition->contenu;
                } else {
                    // Question ouverte à réponse libre : enregistrée en base pour évaluation par Mistral (Étape 7)
                    if ($contenuTexte === null || $contenuTexte === '') {
                        throw ValidationException::withMessages([
                            "reponses.$index.contenu" => ["Une réponse textuelle est obligatoire pour la question ouverte ID {$questionId}."],
                        ]);
                    }

                    $estCorrecte = false;
                    $pointsObtenus = 0.0;
                    $contenuFinal = $contenuTexte;
                    $evaluationIaRequise = true;
                }

                $sommePointsObtenus += $pointsObtenus;

                $preparedReponses[] = [
                    'tentative_id' => $lockedTentative->id,
                    'question_id' => $question->id,
                    'proposition_id' => $propositionId,
                    'contenu' => $contenuFinal,
                    'est_correcte' => $estCorrecte,
                    'points_obtenus' => $pointsObtenus,
                ];
            }

            $lockedTentative->reponses()->delete();
            foreach ($preparedReponses as $repRow) {
                Reponse::create($repRow);
            }

            // Plafonnement strict : la note ne peut jamais dépasser le total des points possibles du quiz
            $noteCalculee = min(round($sommePointsObtenus, 2), round($totalPointsPossibles, 2));
            $noteCalculee = max(0.0, $noteCalculee);

            // Si une réponse ouverte nécessite Mistral AI, on ne fige pas arbitrairement une note définitive (note = null ou provisoire avec statut EN_ATTENTE_CORRECTION)
            $statutFinal = $evaluationIaRequise
                ? Tentative::STATUT_EN_ATTENTE_CORRECTION
                : Tentative::STATUT_TERMINEE;

            $noteFinale = $evaluationIaRequise ? null : $noteCalculee;

            $lockedTentative->update([
                'note' => $noteFinale,
                'statut' => $statutFinal,
            ]);

            $progression = Progression::recalculateForChapitre($etudiant->id, $lockedTentative->quiz->chapitre_id);

            $lockedTentative->load(['quiz', 'reponses.question.propositions', 'reponses.proposition']);

            $pourcentageReussite = ($noteFinale !== null && $totalPointsPossibles > 0)
                ? round(($noteFinale / $totalPointsPossibles) * 100, 2)
                : null;

            return [
                'tentative' => $lockedTentative,
                'note' => $noteFinale,
                'score_qcm_provisoire' => $noteCalculee,
                'total_points_possibles' => round($totalPointsPossibles, 2),
                'pourcentage_reussite' => $pourcentageReussite,
                'evaluation_ia_requise' => $evaluationIaRequise,
                'progression_chapitre' => $progression,
            ];
        });

        return response()->json([
            'message' => $result['evaluation_ia_requise']
                ? 'Tentative enregistrée. Les réponses libres sont conservées en attente d\'évaluation par Gemini AI.'
                : 'Tentative soumise et corrigée avec succès.',
            'note' => $result['note'],
            'score_qcm_provisoire' => $result['score_qcm_provisoire'],
            'total_points_possibles' => $result['total_points_possibles'],
            'pourcentage_reussite' => $result['pourcentage_reussite'],
            'evaluation_ia_requise' => $result['evaluation_ia_requise'],
            'progression_chapitre' => $result['progression_chapitre'],
            'tentative' => $result['tentative'],
        ]);
    }

    /**
     * Historique des tentatives de l'étudiant connecté, enrichi avec `total_points_possibles` et `pourcentage_reussite`.
     */
    public function myTentatives(Request $request): JsonResponse
    {
        $etudiant = $request->user();

        $query = Tentative::with([
            'quiz' => function ($q) {
                $q->with('chapitre.parcoursMatiere.matiere')
                    ->withSum('questions as total_points', 'points');
            },
        ])
            ->where('etudiant_id', $etudiant->id)
            ->orderByDesc('date_passage')
            ->orderByDesc('id');

        if ($request->filled('quiz_id')) {
            $query->where('quiz_id', (int) $request->query('quiz_id'));
        }

        if ($request->filled('chapitre_id')) {
            $chapitreId = (int) $request->query('chapitre_id');
            $query->whereHas('quiz', function ($q) use ($chapitreId) {
                $q->where('chapitre_id', $chapitreId);
            });
        }

        if ($request->filled('statut')) {
            $query->where('statut', strtoupper((string) $request->query('statut')));
        }

        $tentatives = $query->get()->map(function (Tentative $t) {
            $totalPoints = round((float) ($t->quiz->total_points ?? 0.0), 2);
            $pourcentageReussite = ($t->note !== null && $totalPoints > 0)
                ? round(((float) $t->note / $totalPoints) * 100, 2)
                : null;

            $arr = $t->toArray();
            $arr['total_points_possibles'] = $totalPoints;
            $arr['pourcentage_reussite'] = $pourcentageReussite;
            return $arr;
        });

        return response()->json([
            'tentatives' => $tentatives,
        ]);
    }

    /**
     * Détail d'une tentative appartenant à l'étudiant connecté.
     * Masque les réponses correctes tant que la tentative est EN_COURS.
     */
    public function showTentative(Request $request, Tentative $tentative): JsonResponse
    {
        $etudiant = $request->user();

        if ((int) $tentative->etudiant_id !== (int) $etudiant->id) {
            return response()->json([
                'message' => 'Accès interdit : cette tentative ne vous appartient pas.',
            ], 403);
        }

        $tentative->load(['quiz.questions.propositions', 'reponses.proposition']);

        $totalPoints = round((float) $tentative->quiz->questions->sum('points'), 2);
        $pourcentageReussite = ($tentative->note !== null && $totalPoints > 0)
            ? round(((float) $tentative->note / $totalPoints) * 100, 2)
            : null;

        if ($tentative->statut === Tentative::STATUT_EN_COURS) {
            $this->hideAnswersFromQuiz($tentative->quiz);
            $tentative->reponses->makeHidden(['est_correcte', 'points_obtenus']);
        }

        return response()->json([
            'tentative' => $tentative,
            'total_points_possibles' => $totalPoints,
            'pourcentage_reussite' => $pourcentageReussite,
        ]);
    }

    /**
     * Applique les filtres de recherche (`type`, `origine`, `chapitre_id`, `matiere_id`, `q`) sur une requête Quiz.
     */
    private function applyQuizFilters($query, Request $request): void
    {
        if ($request->filled('type')) {
            $query->where('type', strtoupper(trim((string) $request->query('type'))));
        }

        if ($request->filled('origine')) {
            $query->where('origine', strtoupper(trim((string) $request->query('origine'))));
        }

        if ($request->filled('chapitre_id')) {
            $query->where('chapitre_id', (int) $request->query('chapitre_id'));
        }

        if ($request->filled('matiere_id')) {
            $matiereId = (int) $request->query('matiere_id');
            $query->whereHas('chapitre.parcoursMatiere', function ($q) use ($matiereId) {
                $q->where('matiere_id', $matiereId);
            });
        }

        if ($request->filled('q')) {
            $search = trim((string) $request->query('q'));
            $query->where(function ($q) use ($search) {
                $q->where('titre', 'ILIKE', "%{$search}%")
                    ->orWhere('description', 'ILIKE', "%{$search}%");
            });
        }
    }

    /**
     * Masque les champs sensibles (explication et est_correcte) sur un Quiz chargé.
     */
    private function hideAnswersFromQuiz(Quiz $quiz): void
    {
        $quiz->questions->each(function (Question $question) {
            $question->makeHidden(['explication']);
            $question->propositions->makeHidden(['est_correcte']);
        });
    }
}
