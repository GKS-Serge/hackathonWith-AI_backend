<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Progression extends Model
{
    public const STATUT_NON_COMMENCE = 'NON_COMMENCE';
    public const STATUT_EN_COURS = 'EN_COURS';
    public const STATUT_TERMINE = 'TERMINE';

    /**
     * Poids maximal (en %) accordé à la simple consultation des cours d'un chapitre.
     * Garantit qu'une simple consultation de cours ne donne jamais 100% (maîtrise complète).
     */
    public const POIDS_MAX_COURS = 50.0;

    /**
     * Poids maximal (en %) accordé à la réussite des quiz/évaluations du chapitre.
     */
    public const POIDS_MAX_QUIZ = 50.0;

    protected $table = 'progressions';

    public $timestamps = false;

    protected $fillable = [
        'etudiant_id',
        'chapitre_id',
        'pourcentage',
        'statut',
        'derniere_consultation',
    ];

    protected function casts(): array
    {
        return [
            'pourcentage' => 'float',
            'derniere_consultation' => 'datetime',
        ];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class, 'etudiant_id');
    }

    public function chapitre(): BelongsTo
    {
        return $this->belongsTo(Chapitre::class, 'chapitre_id');
    }

    /**
     * Détermine strictement le statut en fonction du pourcentage :
     * - 0% = NON_COMMENCE
     * - ]0%, 100%[ = EN_COURS
     * - 100% = TERMINE
     */
    public static function resolveStatutFromPourcentage(float $pourcentage): string
    {
        if ($pourcentage <= 0.0) {
            return self::STATUT_NON_COMMENCE;
        }

        if ($pourcentage >= 100.0) {
            return self::STATUT_TERMINE;
        }

        return self::STATUT_EN_COURS;
    }

    /**
     * Recalcule et met à jour automatiquement la progression d'un étudiant sur un chapitre
     * après la consultation d'un cours ou la soumission d'un quiz.
     *
     * Règle métier documentée :
     * 1. Consultation des cours (max 50%) : (cours consultés / total cours du chapitre) * 50.
     *    Une simple consultation de tous les cours plafonne donc à 50% (EN_COURS) et ne vaut jamais 100% (TERMINE).
     * 2. Maîtrise des quiz (max 50%, ou 100% s'il n'y a aucun cours dans le chapitre) :
     *    Moyenne des meilleurs taux de réussite (meilleure note / total points du quiz) sur les quiz du chapitre.
     */
    public static function recalculateForChapitre(int $etudiantId, int $chapitreId): self
    {
        $totalCours = Cours::where('chapitre_id', $chapitreId)->count();
        $coursConsultes = 0;

        if ($totalCours > 0) {
            $coursIds = Cours::where('chapitre_id', $chapitreId)->pluck('id');
            $coursConsultes = ConsultationCours::where('etudiant_id', $etudiantId)
                ->whereIn('cours_id', $coursIds)
                ->count();
        }

        $quizzes = Quiz::withSum('questions as total_points', 'points')
            ->where('chapitre_id', $chapitreId)
            ->get();
        $totalQuizzes = $quizzes->count();

        $sommeRatiosQuiz = 0.0;
        $auMoinsUneActivite = ($coursConsultes > 0);

        if ($totalQuizzes > 0) {
            foreach ($quizzes as $quiz) {
                $totalPointsQuiz = max(0.0, (float) ($quiz->total_points ?? 0.0));

                $tentativesQuiz = Tentative::where('etudiant_id', $etudiantId)
                    ->where('quiz_id', $quiz->id)
                    ->whereIn('statut', [Tentative::STATUT_TERMINEE, Tentative::STATUT_EN_ATTENTE_CORRECTION])
                    ->get();

                if ($tentativesQuiz->isNotEmpty()) {
                    $auMoinsUneActivite = true;
                    $meilleureNote = (float) ($tentativesQuiz->whereNotNull('note')->max('note') ?? 0.0);
                    if ($totalPointsQuiz > 0) {
                        $sommeRatiosQuiz += min(1.0, max(0.0, $meilleureNote / $totalPointsQuiz));
                    }
                }
            }
        }

        if ($totalCours > 0 && $totalQuizzes > 0) {
            $partCours = ($coursConsultes / $totalCours) * self::POIDS_MAX_COURS;
            $partQuiz = ($sommeRatiosQuiz / $totalQuizzes) * self::POIDS_MAX_QUIZ;
            $pourcentageCalcule = round($partCours + $partQuiz, 2);
        } elseif ($totalCours > 0 && $totalQuizzes === 0) {
            // Sans quiz d'évaluation, la simple consultation des cours plafonne à 50% (EN_COURS)
            $pourcentageCalcule = round(($coursConsultes / $totalCours) * self::POIDS_MAX_COURS, 2);
        } elseif ($totalCours === 0 && $totalQuizzes > 0) {
            $pourcentageCalcule = round(($sommeRatiosQuiz / $totalQuizzes) * 100.0, 2);
        } else {
            $pourcentageCalcule = 0.0;
        }

        // Si l'étudiant a démarré une activité (ex: quiz soumis avec 0/5), garantir au moins 5% pour refléter le statut EN_COURS
        if ($auMoinsUneActivite && $pourcentageCalcule <= 0.0) {
            $pourcentageCalcule = 5.0;
        }

        $pourcentageCalcule = min(100.0, max(0.0, $pourcentageCalcule));
        $statut = self::resolveStatutFromPourcentage($pourcentageCalcule);

        return self::updateOrCreate(
            [
                'etudiant_id' => $etudiantId,
                'chapitre_id' => $chapitreId,
            ],
            [
                'pourcentage' => $pourcentageCalcule,
                'statut' => $statut,
                'derniere_consultation' => now(),
            ]
        );
    }
}
