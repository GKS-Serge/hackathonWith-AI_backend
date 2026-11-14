<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PedagogicalAiInterface;
use App\Exceptions\AiServiceException;
use App\Http\Controllers\Controller;
use App\Models\Chapitre;
use App\Models\Cours;
use App\Models\Etudiant;
use App\Models\Progression;
use App\Models\Proposition;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Reponse;
use App\Models\Resume;
use App\Models\Tentative;
use App\Models\Transcription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiController extends Controller
{
    public function __construct(
        protected PedagogicalAiInterface $aiService
    ) {
    }

    /**
     * 1A. Génère un résumé IA à partir d'un cours (`cours_id` renseigné, `transcription_id = null`).
     * Évite les doublons : retourne le résumé existant sauf si `force = true`.
     */
    public function generateCoursResume(Request $request, Cours $cours): JsonResponse
    {
        /** @var Etudiant $etudiant */
        $etudiant = $request->user();
        $cours->load('chapitre.parcoursMatiere');

        $this->authorizeParcoursAccess($etudiant, (int) $cours->chapitre->parcoursMatiere->parcours_id);

        $force = $request->boolean('force', false);

        $existing = Resume::where('cours_id', $cours->id)
            ->whereNull('transcription_id')
            ->first();

        if ($existing && ! $force) {
            return response()->json([
                'message' => 'Résumé existant récupéré (aucun appel IA redondant).',
                'deja_existant' => true,
                'resume' => $existing,
            ]);
        }

        [$niveauNom, $serieCode] = $this->resolveStudentLevelAndSerie($etudiant);

        try {
            $contenuResume = $this->aiService->generateResume(
                'Cours écrit',
                $cours->titre,
                $cours->contenu,
                $niveauNom,
                $serieCode
            );
        } catch (AiServiceException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getHttpStatus());
        }

        $resume = DB::transaction(function () use ($cours, $existing, $contenuResume) {
            if ($existing) {
                $existing->update([
                    'contenu' => $contenuResume,
                    'date_creation' => now(),
                ]);
                return $existing->fresh();
            }

            return Resume::create([
                'cours_id' => $cours->id,
                'transcription_id' => null,
                'contenu' => $contenuResume,
                'date_creation' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Résumé du cours généré par Gemini AI avec succès.',
            'deja_existant' => false,
            'resume' => $resume,
        ], $existing ? 200 : 201);
    }

    /**
     * 1B. Génère un résumé IA à partir d'une transcription vidéo (`transcription_id` renseigné, `cours_id = null`).
     * Évite les doublons : retourne le résumé existant sauf si `force = true`.
     */
    public function generateTranscriptionResume(Request $request, Transcription $transcription): JsonResponse
    {
        /** @var Etudiant $etudiant */
        $etudiant = $request->user();
        $transcription->load('video.chapitre.parcoursMatiere');

        $this->authorizeParcoursAccess($etudiant, (int) $transcription->video->chapitre->parcoursMatiere->parcours_id);

        $force = $request->boolean('force', false);

        $existing = Resume::where('transcription_id', $transcription->id)
            ->whereNull('cours_id')
            ->first();

        if ($existing && ! $force) {
            return response()->json([
                'message' => 'Résumé de transcription existant récupéré (aucun appel IA redondant).',
                'deja_existant' => true,
                'resume' => $existing,
            ]);
        }

        [$niveauNom, $serieCode] = $this->resolveStudentLevelAndSerie($etudiant);

        try {
            $contenuResume = $this->aiService->generateResume(
                'Transcription vidéo',
                $transcription->video->titre,
                $transcription->contenu,
                $niveauNom,
                $serieCode
            );
        } catch (AiServiceException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getHttpStatus());
        }

        $resume = DB::transaction(function () use ($transcription, $existing, $contenuResume) {
            if ($existing) {
                $existing->update([
                    'contenu' => $contenuResume,
                    'date_creation' => now(),
                ]);
                return $existing->fresh();
            }

            return Resume::create([
                'cours_id' => null,
                'transcription_id' => $transcription->id,
                'contenu' => $contenuResume,
                'date_creation' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Résumé de la transcription généré par Gemini AI avec succès.',
            'deja_existant' => false,
            'resume' => $resume,
        ], $existing ? 200 : 201);
    }

    /**
     * 2. Explication pédagogique d'une notion ou d'un passage d'un cours,
     * adaptée au niveau et à la série de l'étudiant, avec mise en cache anti-abus.
     */
    public function explainCours(Request $request, Cours $cours): JsonResponse
    {
        /** @var Etudiant $etudiant */
        $etudiant = $request->user();
        $cours->load('chapitre.parcoursMatiere');

        $this->authorizeParcoursAccess($etudiant, (int) $cours->chapitre->parcoursMatiere->parcours_id);

        $validated = $request->validate([
            'notion' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        [$niveauNom, $serieCode] = $this->resolveStudentLevelAndSerie($etudiant);
        $notion = trim($validated['notion']);

        $cacheKey = 'gemini_explain_' . sha1($cours->id . '|' . $niveauNom . '|' . $serieCode . '|' . mb_strtolower($notion));

        try {
            $explication = Cache::remember($cacheKey, now()->addHours(12), function () use ($cours, $notion, $niveauNom, $serieCode) {
                return $this->aiService->explainConcept(
                    $cours->titre,
                    $cours->contenu,
                    $notion,
                    $niveauNom,
                    $serieCode
                );
            });
        } catch (AiServiceException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getHttpStatus());
        }

        return response()->json([
            'cours_id' => $cours->id,
            'niveau' => $niveauNom,
            'serie' => $serieCode,
            'explication' => $explication,
        ]);
    }

    /**
     * 3. Génère un Quiz QCM (`QUIZ` ou `REVISION`) à partir des contenus d'un chapitre,
     * l'enregistre sous transaction et masque les bonnes réponses avant soumission.
     */
    public function generateQuiz(Request $request, Chapitre $chapitre): JsonResponse
    {
        /** @var Etudiant $etudiant */
        $etudiant = $request->user();
        $chapitre->load(['parcoursMatiere', 'cours', 'videos.transcription']);

        $this->authorizeParcoursAccess($etudiant, (int) $chapitre->parcoursMatiere->parcours_id);

        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:QUIZ,REVISION'],
            'nombre_questions' => ['nullable', 'integer', 'min:2', 'max:10'],
        ]);

        $type = strtoupper($validated['type'] ?? Quiz::TYPE_QUIZ);
        $nbQuestions = (int) ($validated['nombre_questions'] ?? 3);

        $contenuSource = $this->aggregateChapitreSourceContent($chapitre);
        if ($contenuSource === '') {
            throw ValidationException::withMessages([
                'chapitre' => ['Ce chapitre ne contient aucun cours ni transcription permettant de générer un quiz.'],
            ]);
        }

        [$niveauNom, $serieCode] = $this->resolveStudentLevelAndSerie($etudiant);

        try {
            $generated = $this->aiService->generateQuiz(
                $chapitre->titre,
                $contenuSource,
                $type,
                $nbQuestions,
                $niveauNom,
                $serieCode
            );
        } catch (AiServiceException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getHttpStatus());
        }

        $quiz = $this->persistGeneratedQuizOrExercice($chapitre->id, $type, $generated);

        return response()->json([
            'message' => 'Quiz généré par Gemini AI avec succès.',
            'quiz' => $quiz,
        ], 201);
    }

    /**
     * 4. Génère un Exercice progressif (`type = EXERCICE`) adapté au chapitre et au niveau de l'étudiant.
     */
    public function generateExercice(Request $request, Chapitre $chapitre): JsonResponse
    {
        /** @var Etudiant $etudiant */
        $etudiant = $request->user();
        $chapitre->load(['parcoursMatiere', 'cours', 'videos.transcription']);

        $this->authorizeParcoursAccess($etudiant, (int) $chapitre->parcoursMatiere->parcours_id);

        $validated = $request->validate([
            'nombre_questions' => ['nullable', 'integer', 'min:2', 'max:8'],
        ]);

        $nbQuestions = (int) ($validated['nombre_questions'] ?? 3);

        $contenuSource = $this->aggregateChapitreSourceContent($chapitre);
        if ($contenuSource === '') {
            throw ValidationException::withMessages([
                'chapitre' => ['Ce chapitre ne contient aucun cours ni transcription permettant de générer un exercice.'],
            ]);
        }

        [$niveauNom, $serieCode] = $this->resolveStudentLevelAndSerie($etudiant);

        try {
            $generated = $this->aiService->generateExercice(
                $chapitre->titre,
                $contenuSource,
                $nbQuestions,
                $niveauNom,
                $serieCode
            );
        } catch (AiServiceException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getHttpStatus());
        }

        $exercice = $this->persistGeneratedQuizOrExercice($chapitre->id, Quiz::TYPE_EXERCICE, $generated);

        return response()->json([
            'message' => 'Exercice progressif généré par Gemini AI avec succès.',
            'quiz' => $exercice,
        ], 201);
    }

    /**
     * 5. Évaluation assistée par Gemini des réponses textuelles libres d'une tentative (`EN_ATTENTE_CORRECTION`).
     * Valide et plafonne les points côté Laravel, met à jour `reponses`, finalise la `tentative` (`TERMINEE`)
     * et recalcule la progression du chapitre.
     */
    public function evaluateTentativeOpenResponses(Request $request, Tentative $tentative): JsonResponse
    {
        /** @var Etudiant $etudiant */
        $etudiant = $request->user();

        if ((int) $tentative->etudiant_id !== (int) $etudiant->id) {
            return response()->json([
                'message' => 'Accès interdit : cette tentative ne vous appartient pas.',
            ], 403);
        }

        $tentative->load(['quiz.chapitre.cours', 'quiz.chapitre.videos.transcription', 'quiz.questions.propositions', 'reponses.question']);

        if ($tentative->statut === Tentative::STATUT_TERMINEE) {
            throw ValidationException::withMessages([
                'tentative' => ['Cette tentative est déjà terminée et intégralement corrigée.'],
            ]);
        }

        if ($tentative->statut !== Tentative::STATUT_EN_ATTENTE_CORRECTION) {
            throw ValidationException::withMessages([
                'tentative' => ['Cette tentative doit d\'abord être soumise avant de lancer l\'évaluation IA des réponses libres.'],
            ]);
        }

        $reponsesOuvertes = $tentative->reponses->filter(function (Reponse $r) {
            return $r->proposition_id === null;
        });

        if ($reponsesOuvertes->isEmpty()) {
            throw ValidationException::withMessages([
                'reponses' => ['Aucune réponse textuelle libre en attente d\'évaluation dans cette tentative.'],
            ]);
        }

        $questionsPayload = $reponsesOuvertes->map(function (Reponse $r) {
            return [
                'reponse_id' => $r->id,
                'question_id' => $r->question_id,
                'enonce_question' => $r->question->enonce,
                'points_max' => (float) $r->question->points,
                'elements_attendus' => $r->question->explication ?? 'Réponse cohérente avec le cours du chapitre.',
                'reponse_etudiant' => $r->contenu,
            ];
        })->values()->all();

        $contenuSource = $this->aggregateChapitreSourceContent($tentative->quiz->chapitre);
        [$niveauNom, $serieCode] = $this->resolveStudentLevelAndSerie($etudiant);

        try {
            $evaluations = $this->aiService->evaluateOpenResponses(
                $tentative->quiz->titre,
                $contenuSource,
                $questionsPayload,
                $niveauNom,
                $serieCode
            );
        } catch (AiServiceException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getHttpStatus());
        }

        $result = DB::transaction(function () use ($tentative, $etudiant, $evaluations) {
            $lockedTentative = Tentative::with(['quiz.questions', 'reponses.question'])
                ->where('id', $tentative->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTentative->statut === Tentative::STATUT_TERMINEE) {
                throw ValidationException::withMessages([
                    'tentative' => ['Cette tentative a déjà été finalisée.'],
                ]);
            }

            foreach ($lockedTentative->reponses as $reponse) {
                if (isset($evaluations[$reponse->id])) {
                    $eval = $evaluations[$reponse->id];
                    $maxPts = max(0.0, (float) $reponse->question->points);
                    $ptsValides = max(0.0, min((float) $eval['points_obtenus'], $maxPts));

                    $reponse->update([
                        'points_obtenus' => $ptsValides,
                        'est_correcte' => (bool) $eval['est_correcte'],
                    ]);
                }
            }

            $lockedTentative->load('reponses');
            $totalPointsPossibles = max(0.0, (float) $lockedTentative->quiz->questions->sum('points'));
            $sommePointsObtenus = (float) $lockedTentative->reponses->sum('points_obtenus');

            $noteFinale = min(round($sommePointsObtenus, 2), round($totalPointsPossibles, 2));
            $noteFinale = max(0.0, $noteFinale);

            $lockedTentative->update([
                'note' => $noteFinale,
                'statut' => Tentative::STATUT_TERMINEE,
            ]);

            $progression = Progression::recalculateForChapitre($etudiant->id, $lockedTentative->quiz->chapitre_id);

            $lockedTentative->load(['quiz', 'reponses.question.propositions', 'reponses.proposition']);

            return [
                'tentative' => $lockedTentative,
                'note' => $noteFinale,
                'total_points_possibles' => round($totalPointsPossibles, 2),
                'pourcentage_reussite' => $totalPointsPossibles > 0 ? round(($noteFinale / $totalPointsPossibles) * 100, 2) : null,
                'evaluations_detaillees' => array_values($evaluations),
                'progression_chapitre' => $progression,
            ];
        });

        return response()->json([
            'message' => 'Réponses libres évaluées par Gemini AI et tentative finalisée avec succès.',
            'note' => $result['note'],
            'total_points_possibles' => $result['total_points_possibles'],
            'pourcentage_reussite' => $result['pourcentage_reussite'],
            'evaluations_detaillees' => $result['evaluations_detaillees'],
            'progression_chapitre' => $result['progression_chapitre'],
            'tentative' => $result['tentative'],
        ]);
    }

    /**
     * Enregistre de manière atomique un Quiz/Exercice généré par Gemini avec ses questions et propositions,
     * puis masque `est_correcte` et `explication` avant de retourner l'objet au client.
     */
    private function persistGeneratedQuizOrExercice(int $chapitreId, string $type, array $generated): Quiz
    {
        $quiz = DB::transaction(function () use ($chapitreId, $type, $generated) {
            $newQuiz = Quiz::create([
                'chapitre_id' => $chapitreId,
                'titre' => $generated['titre'],
                'description' => $generated['description'],
                'type' => $type,
                'origine' => Quiz::ORIGINE_GEMINI,
                'date_creation' => now(),
            ]);

            foreach ($generated['questions'] as $qData) {
                $question = Question::create([
                    'quiz_id' => $newQuiz->id,
                    'enonce' => $qData['enonce'],
                    'type' => $qData['type'],
                    'points' => $qData['points'],
                    'explication' => $qData['explication'],
                ]);

                foreach ($qData['propositions'] as $pData) {
                    Proposition::create([
                        'question_id' => $question->id,
                        'contenu' => $pData['contenu'],
                        'est_correcte' => $pData['est_correcte'],
                    ]);
                }
            }

            return $newQuiz->load('questions.propositions');
        });

        // Sécurité : ne jamais exposer les bonnes réponses ni les explications avant la soumission d'une tentative
        $quiz->questions->each(function (Question $question) {
            $question->makeHidden(['explication']);
            $question->propositions->makeHidden(['est_correcte']);
        });

        return $quiz;
    }

    /**
     * Vérifie que l'étudiant connecté appartient bien au parcours scolaire de la ressource demandée.
     */
    private function authorizeParcoursAccess(Etudiant $etudiant, int $resourceParcoursId): void
    {
        if ((int) $etudiant->parcours_id !== $resourceParcoursId) {
            abort(403, "Accès refusé : ce contenu pédagogique n'appartient pas à votre parcours scolaire.");
        }
    }

    /**
     * Récupère le nom du Niveau et le code de la Série de l'étudiant connecté.
     */
    private function resolveStudentLevelAndSerie(Etudiant $etudiant): array
    {
        $etudiant->loadMissing(['parcoursScolaire.niveau', 'parcoursScolaire.serie']);

        $niveauNom = $etudiant->parcoursScolaire?->niveau?->nom ?? 'Terminale';
        $serieCode = $etudiant->parcoursScolaire?->serie?->code ?? 'C';

        return [$niveauNom, $serieCode];
    }

    /**
     * Agrège les contenus des cours et des transcriptions vidéo d'un chapitre comme source unique de vérité.
     */
    private function aggregateChapitreSourceContent(Chapitre $chapitre): string
    {
        $parts = [];

        foreach ($chapitre->cours as $cours) {
            $parts[] = "=== COURS : {$cours->titre} ===\n{$cours->contenu}";
        }

        foreach ($chapitre->videos as $video) {
            if ($video->transcription && trim((string) $video->transcription->contenu) !== '') {
                $parts[] = "=== TRANSCRIPTION VIDÉO : {$video->titre} ===\n{$video->transcription->contenu}";
            }
        }

        return implode("\n\n", $parts);
    }
}
