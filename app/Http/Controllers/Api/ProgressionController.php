<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapitre;
use App\Models\ConsultationCours;
use App\Models\ParcoursMatiere;
use App\Models\Progression;
use App\Models\Tentative;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProgressionController extends Controller
{
    /**
     * Liste toutes les progressions de l'étudiant connecté.
     */
    public function index(Request $request): JsonResponse
    {
        $etudiant = $request->user();

        $progressions = Progression::with(['chapitre.parcoursMatiere.matiere'])
            ->where('etudiant_id', $etudiant->id)
            ->orderByDesc('derniere_consultation')
            ->get();

        return response()->json([
            'progressions' => $progressions,
        ]);
    }

    /**
     * Consulte la progression de l'étudiant connecté sur un chapitre spécifique.
     */
    public function showByChapitre(Request $request, Chapitre $chapitre): JsonResponse
    {
        $etudiant = $request->user();

        $progression = Progression::with('chapitre')
            ->where('etudiant_id', $etudiant->id)
            ->where('chapitre_id', $chapitre->id)
            ->first();

        if (! $progression) {
            return response()->json([
                'progression' => [
                    'etudiant_id' => $etudiant->id,
                    'chapitre_id' => $chapitre->id,
                    'pourcentage' => 0.0,
                    'statut' => Progression::STATUT_NON_COMMENCE,
                    'derniere_consultation' => null,
                ],
            ]);
        }

        return response()->json([
            'progression' => $progression,
        ]);
    }

    /**
     * Crée ou met à jour explicitement la progression d'un étudiant sur un chapitre sans doublon,
     * avec synchronisation stricte côté serveur entre pourcentage et statut :
     * - 0% = NON_COMMENCE
     * - ]0%, 100%[ = EN_COURS
     * - 100% = TERMINE
     */
    public function upsert(Request $request, Chapitre $chapitre): JsonResponse
    {
        $etudiant = $request->user();

        $validated = $request->validate([
            'pourcentage' => ['required_without:statut', 'nullable', 'numeric', 'min:0', 'max:100'],
            'statut' => ['nullable', 'string', 'in:' . implode(',', [
                Progression::STATUT_NON_COMMENCE,
                Progression::STATUT_EN_COURS,
                Progression::STATUT_TERMINE,
            ])],
        ]);

        $hasPourcentage = array_key_exists('pourcentage', $validated) && $validated['pourcentage'] !== null;
        $hasStatut = array_key_exists('statut', $validated) && $validated['statut'] !== null;

        if ($hasPourcentage) {
            $pourcentage = round((float) $validated['pourcentage'], 2);
            $expectedStatut = Progression::resolveStatutFromPourcentage($pourcentage);

            if ($hasStatut && $validated['statut'] !== $expectedStatut) {
                throw ValidationException::withMessages([
                    'statut' => [
                        "Incohérence entre le pourcentage ({$pourcentage}%) et le statut ({$validated['statut']}). "
                        . "Le statut attendu pour {$pourcentage}% est {$expectedStatut}.",
                    ],
                ]);
            }

            $statut = $expectedStatut;
        } else {
            $statut = $validated['statut'];

            if ($statut === Progression::STATUT_NON_COMMENCE) {
                $pourcentage = 0.0;
            } elseif ($statut === Progression::STATUT_TERMINE) {
                $pourcentage = 100.0;
            } else {
                $existing = Progression::where('etudiant_id', $etudiant->id)
                    ->where('chapitre_id', $chapitre->id)
                    ->first();

                if ($existing && $existing->pourcentage > 0 && $existing->pourcentage < 100) {
                    $pourcentage = (float) $existing->pourcentage;
                } else {
                    throw ValidationException::withMessages([
                        'pourcentage' => [
                            'Pour le statut EN_COURS, veuillez fournir un pourcentage strictement compris entre 0 et 100.',
                        ],
                    ]);
                }
            }
        }

        $progression = Progression::updateOrCreate(
            [
                'etudiant_id' => $etudiant->id,
                'chapitre_id' => $chapitre->id,
            ],
            [
                'pourcentage' => $pourcentage,
                'statut' => $statut,
                'derniere_consultation' => now(),
            ]
        );

        return response()->json([
            'message' => 'Progression enregistrée avec succès.',
            'progression' => $progression,
        ]);
    }

    /**
     * Tableau de bord personnalisé complet de l'étudiant connecté :
     * - Profil & parcours
     * - Statistiques globales
     * - Progression détaillée par matière
     * - Section "Reprendre mon apprentissage" (dernier chapitre et dernier cours consultés)
     * - Derniers cours consultés et dernières tentatives de quiz enrichies
     */
    public function dashboard(Request $request): JsonResponse
    {
        $etudiant = $request->user();
        $etudiant->load(['parcoursScolaire.niveau', 'parcoursScolaire.serie']);

        $parcoursMatieres = ParcoursMatiere::with(['matiere', 'chapitres.quizzes'])
            ->where('parcours_id', $etudiant->parcours_id)
            ->orderBy('ordre')
            ->get();

        $progressions = Progression::with('chapitre.parcoursMatiere.matiere')
            ->where('etudiant_id', $etudiant->id)
            ->orderByDesc('derniere_consultation')
            ->get();
        $progressionsByChapitre = $progressions->keyBy('chapitre_id');

        $tentatives = Tentative::with([
            'quiz' => function ($q) {
                $q->with('chapitre.parcoursMatiere.matiere')
                    ->withSum('questions as total_points', 'points');
            },
        ])
            ->where('etudiant_id', $etudiant->id)
            ->orderByDesc('date_passage')
            ->orderByDesc('id')
            ->get();

        $tentativesTerminees = $tentatives->where('statut', Tentative::STATUT_TERMINEE);
        $tentativesByQuiz = $tentativesTerminees->groupBy('quiz_id');

        $totalChapitresParcours = 0;
        $sommeProgressionChapitres = 0.0;

        $progressionParMatiere = $parcoursMatieres->map(function (ParcoursMatiere $pm) use (
            $progressionsByChapitre,
            $tentativesByQuiz,
            &$totalChapitresParcours,
            &$sommeProgressionChapitres
        ) {
            $chapitres = $pm->chapitres;
            $nbChapitres = $chapitres->count();
            $totalChapitresParcours += $nbChapitres;

            $termines = 0;
            $enCours = 0;
            $sommeMatiere = 0.0;
            $pourcentagesReussiteQuiz = [];

            foreach ($chapitres as $chapitre) {
                $prog = $progressionsByChapitre->get($chapitre->id);
                if ($prog) {
                    $sommeMatiere += (float) $prog->pourcentage;
                    $sommeProgressionChapitres += (float) $prog->pourcentage;
                    if ($prog->statut === Progression::STATUT_TERMINE) {
                        $termines++;
                    } elseif ($prog->statut === Progression::STATUT_EN_COURS) {
                        $enCours++;
                    }
                }

                foreach ($chapitre->quizzes as $quiz) {
                    $tQuiz = $tentativesByQuiz->get($quiz->id);
                    if ($tQuiz && $tQuiz->isNotEmpty()) {
                        foreach ($tQuiz as $t) {
                            $totalPts = (float) ($t->quiz->total_points ?? 0.0);
                            if ($t->note !== null && $totalPts > 0) {
                                $pourcentagesReussiteQuiz[] = ((float) $t->note / $totalPts) * 100.0;
                            }
                        }
                    }
                }
            }

            $pourcentageMatiere = $nbChapitres > 0 ? round($sommeMatiere / $nbChapitres, 2) : 0.0;

            return [
                'parcours_matiere_id' => $pm->id,
                'ordre' => $pm->ordre,
                'matiere' => $pm->matiere,
                'chapitres_total' => $nbChapitres,
                'chapitres_termines' => $termines,
                'chapitres_en_cours' => $enCours,
                'pourcentage_progression' => $pourcentageMatiere,
                'statut' => Progression::resolveStatutFromPourcentage($pourcentageMatiere),
                'taux_reussite_moyen_quiz' => ! empty($pourcentagesReussiteQuiz)
                    ? round(array_sum($pourcentagesReussiteQuiz) / count($pourcentagesReussiteQuiz), 2)
                    : null,
            ];
        });

        $chapitresTerminesGlobal = $progressions->where('statut', Progression::STATUT_TERMINE)->count();
        $chapitresEnCoursGlobal = $progressions->where('statut', Progression::STATUT_EN_COURS)->count();
        $pourcentageGlobal = $totalChapitresParcours > 0
            ? round($sommeProgressionChapitres / $totalChapitresParcours, 2)
            : 0.0;

        $consultationsCours = ConsultationCours::with('cours.chapitre.parcoursMatiere.matiere')
            ->where('etudiant_id', $etudiant->id)
            ->orderByDesc('date_consultation')
            ->orderByDesc('id')
            ->get();

        $dernieresTentativesFormattees = $tentatives->take(5)->map(function (Tentative $t) {
            $totalPts = round((float) ($t->quiz->total_points ?? 0.0), 2);
            $pct = ($t->note !== null && $totalPts > 0)
                ? round(((float) $t->note / $totalPts) * 100, 2)
                : null;
            $arr = $t->toArray();
            $arr['total_points_possibles'] = $totalPts;
            $arr['pourcentage_reussite'] = $pct;
            return $arr;
        })->values();

        return response()->json([
            'etudiant' => $etudiant,
            'statistiques' => [
                'total_chapitres_parcours' => $totalChapitresParcours,
                'chapitres_termines' => $chapitresTerminesGlobal,
                'chapitres_en_cours' => $chapitresEnCoursGlobal,
                'progression_globale_pourcentage' => $pourcentageGlobal,
                'cours_consultes_count' => $consultationsCours->count(),
                'tentatives_terminees_count' => $tentativesTerminees->count(),
                'moyenne_notes_quiz' => $tentativesTerminees->whereNotNull('note')->isNotEmpty()
                    ? round((float) $tentativesTerminees->whereNotNull('note')->avg('note'), 2)
                    : null,
            ],
            'progression_par_matiere' => $progressionParMatiere,
            'reprendre_apprentissage' => [
                'derniere_progression_chapitre' => $progressions->first(),
                'dernier_cours_consulte' => $consultationsCours->first(),
            ],
            'derniers_cours_consultes' => $consultationsCours->take(5)->values(),
            'dernieres_tentatives' => $dernieresTentativesFormattees,
        ]);
    }
}
