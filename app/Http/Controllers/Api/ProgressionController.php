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

        $parcoursMatieres = ParcoursMatiere::with([
            'matiere',
            'chapitres' => function ($q) {
                $q->where('est_demo', false);
            },
            'chapitres.quizzes' => function ($q) {
                $q->withSum('questions as total_points', 'points');
            },
            'chapitres.cours',
            'chapitres.videos',
        ])
            ->where('parcours_id', $etudiant->parcours_id)
            ->whereHas('chapitres', function ($q) {
                $q->where('est_demo', false);
            })
            ->orderBy('ordre')
            ->get();

        $chapitresParcours = $parcoursMatieres->flatMap->chapitres;

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

        $chapitresTerminesGlobal = $progressions
            ->whereIn('chapitre_id', $chapitresParcours->pluck('id'))
            ->where('statut', Progression::STATUT_TERMINE)
            ->count();
        $chapitresEnCoursGlobal = $progressions
            ->whereIn('chapitre_id', $chapitresParcours->pluck('id'))
            ->where('statut', Progression::STATUT_EN_COURS)
            ->count();
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

        // ----------------------------------------------------
        // ENRICHISSEMENT PHASE 4 : Calcul des Recommandations Pédagogiques
        // ----------------------------------------------------
        $allowedChapitreIds = $chapitresParcours->pluck('id');
        $recommandations = [
            'a_reviser_prioritairement' => $this->computeRevisionsPrioritaires(
                $etudiant->id,
                $allowedChapitreIds,
                $chapitresParcours,
                $tentativesByQuiz,
                $progressionsByChapitre
            ),
            'reprendre_apprentissage' => $this->computeRepriseApprentissage(
                $etudiant->id,
                $progressions->where('statut', Progression::STATUT_EN_COURS),
                $chapitresParcours
            ),
            'prochains_chapitres' => $this->computeProchainsChapitres(
                $parcoursMatieres,
                $progressionsByChapitre
            ),
        ];

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
            'recommandations' => $recommandations,
            'derniers_cours_consultes' => $consultationsCours->take(5)->values(),
            'dernieres_tentatives' => $dernieresTentativesFormattees,
        ]);
    }

    /**
     * Calcule les révisions prioritaires pour l'étudiant.
     * Règle 1 : Meilleur score < 60% sur les quiz tentés.
     * Règle 2 : Exclusion absolue de tout quiz ayant déjà un meilleur score >= 60%.
     * Règle 3 : Pour les chapitres EN_COURS bloqués depuis > 48h sans quiz rattaché, action RELIRE_COURS ou VOIR_VIDEO réelle.
     */
    protected function computeRevisionsPrioritaires(
        int $etudiantId,
        $allowedChapitreIds,
        $chapitresParcours,
        $tentativesByQuiz,
        $progressionsByChapitre
    ): array {
        $revisions = [];
        $quizTraites = [];

        // 1. Analyse des quiz tentés dans le parcours
        foreach ($chapitresParcours as $chapitre) {
            foreach ($chapitre->quizzes as $quiz) {
                $tList = $tentativesByQuiz->get($quiz->id);
                if ($tList && $tList->isNotEmpty()) {
                    $totalPts = (float) ($quiz->total_points ?? 0.0);
                    $meilleureNote = $tList->whereNotNull('note')->max('note');

                    if ($meilleureNote !== null && $totalPts > 0) {
                        $meilleurScorePct = round(((float) $meilleureNote / $totalPts) * 100.0, 2);

                        // Si le meilleur score est >= 60%, exclusion stricte et absolue
                        if ($meilleurScorePct >= 60.0) {
                            $quizTraites[$quiz->id] = true;
                            continue;
                        }

                        // Échec avéré : meilleur score < 60%
                        $quizTraites[$quiz->id] = true;
                        $revisions[] = [
                            'chapitre_id' => $chapitre->id,
                            'chapitre_titre' => $chapitre->titre,
                            'matiere' => [
                                'id' => $chapitre->parcoursMatiere?->matiere?->id,
                                'nom' => $chapitre->parcoursMatiere?->matiere?->nom,
                                'code' => $chapitre->parcoursMatiere?->matiere?->code,
                            ],
                            'quiz_id' => $quiz->id,
                            'quiz_titre' => $quiz->titre,
                            'meilleur_score_actuel' => $meilleurScorePct,
                            'seuil_reussite' => 60.0,
                            'raison' => "Meilleur score insuffisant ({$meilleurScorePct}% / 60% requis)",
                            'action' => 'REPASSER_QUIZ',
                        ];
                    }
                }
            }
        }

        // Tri par score le plus faible d'abord
        usort($revisions, fn($a, $b) => $a['meilleur_score_actuel'] <=> $b['meilleur_score_actuel']);

        // 2. Chapitres EN_COURS bloqués depuis plus de 48 heures sans quiz rattaché
        $seuil48h = now()->subHours(48);
        foreach ($chapitresParcours as $chapitre) {
            if (count($revisions) >= 3) {
                break;
            }

            $prog = $progressionsByChapitre->get($chapitre->id);
            if ($prog && $prog->statut === Progression::STATUT_EN_COURS) {
                // S'il existe des quiz sur ce chapitre, la règle des quiz s'applique (on n'ajoute pas de faux doublon)
                if ($chapitre->quizzes->isNotEmpty()) {
                    continue;
                }

                $derniereDate = $prog->derniere_consultation;
                if ($derniereDate && $derniereDate < $seuil48h) {
                    // Vérification de la ressource réellement existante
                    $hasCours = $chapitre->cours->isNotEmpty();
                    $hasVideos = $chapitre->videos->where('est_integrable', true)->isNotEmpty();

                    $actionReelle = null;
                    $raisonReelle = null;

                    if ($hasCours) {
                        $actionReelle = 'RELIRE_COURS';
                        $raisonReelle = 'Chapitre en cours sans activité depuis plus de 48h';
                    } elseif ($hasVideos) {
                        $actionReelle = 'VOIR_VIDEO';
                        $raisonReelle = 'Chapitre en cours avec vidéo disponible à revoir';
                    }

                    if ($actionReelle !== null) {
                        $revisions[] = [
                            'chapitre_id' => $chapitre->id,
                            'chapitre_titre' => $chapitre->titre,
                            'matiere' => [
                                'id' => $chapitre->parcoursMatiere?->matiere?->id,
                                'nom' => $chapitre->parcoursMatiere?->matiere?->nom,
                                'code' => $chapitre->parcoursMatiere?->matiere?->code,
                            ],
                            'quiz_id' => null,
                            'quiz_titre' => null,
                            'meilleur_score_actuel' => null,
                            'seuil_reussite' => 60.0,
                            'raison' => $raisonReelle,
                            'action' => $actionReelle,
                        ];
                    }
                }
            }
        }

        return array_slice($revisions, 0, 3);
    }

    /**
     * Calcule la reprise d'apprentissage pour les chapitres EN_COURS avec action vérifiée existante.
     */
    protected function computeRepriseApprentissage(int $etudiantId, $progressionsEnCours, $chapitresParcours): array
    {
        $reprises = [];
        $chapitresKeyed = $chapitresParcours->keyBy('id');

        // Consultations des cours de l'étudiant
        $consultationsCours = ConsultationCours::where('etudiant_id', $etudiantId)
            ->get()
            ->keyBy('cours_id');

        // Tentatives de quiz réussies
        $quizReussisIds = Tentative::where('etudiant_id', $etudiantId)
            ->where('statut', Tentative::STATUT_TERMINEE)
            ->where('note', '>=', 60.0)
            ->pluck('quiz_id')
            ->toArray();

        foreach ($progressionsEnCours as $prog) {
            if (count($reprises) >= 3) {
                break;
            }

            $chapitre = $chapitresKeyed->get($prog->chapitre_id);
            if (! $chapitre) {
                continue;
            }

            // Détermination de l'action réelle selon les ressources existantes
            $quizDisponibleNonValide = $chapitre->quizzes->first(function ($q) use ($quizReussisIds) {
                return !in_array($q->id, $quizReussisIds, true);
            });

            $coursNonConsulte = $chapitre->cours->first(function ($c) use ($consultationsCours) {
                return !$consultationsCours->has($c->id);
            });

            $videoIntegrable = $chapitre->videos->where('est_integrable', true)->first();

            $action = null;
            $ressourceCibleId = null;
            $typeRessource = null;
            $raison = null;

            if ($quizDisponibleNonValide) {
                $action = 'PASSER_QUIZ';
                $ressourceCibleId = $quizDisponibleNonValide->id;
                $typeRessource = 'QUIZ';
                $raison = 'Cours consulté, évaluation disponible à valider';
            } elseif ($coursNonConsulte) {
                $action = 'LIRE_COURS';
                $ressourceCibleId = $coursNonConsulte->id;
                $typeRessource = 'COURS';
                $raison = 'Cours disponible restant à consulter dans ce chapitre';
            } elseif ($videoIntegrable) {
                $action = 'VOIR_VIDEO';
                $ressourceCibleId = $videoIntegrable->id;
                $typeRessource = 'VIDEO';
                $raison = 'Vidéo pédagogique disponible pour consolider ce chapitre';
            }

            if ($action !== null) {
                $reprises[] = [
                    'chapitre_id' => $chapitre->id,
                    'chapitre_titre' => $chapitre->titre,
                    'matiere' => [
                        'id' => $chapitre->parcoursMatiere?->matiere?->id,
                        'nom' => $chapitre->parcoursMatiere?->matiere?->nom,
                        'code' => $chapitre->parcoursMatiere?->matiere?->code,
                    ],
                    'pourcentage' => (float) $prog->pourcentage,
                    'statut' => $prog->statut,
                    'ressource_cible_id' => $ressourceCibleId,
                    'type_ressource' => $typeRessource,
                    'raison' => $raison,
                    'action' => $action,
                ];
            }
        }

        return $reprises;
    }

    /**
     * Calcule les prochains chapitres recommandés.
     * Règle :
     * 1. Parcourt les matières du parcours dans leur ordre pédagogique réel (parcours_matieres.ordre ASC).
     * 2. Sélectionne jusqu'à 3 matières distinctes.
     * 3. Pour chaque matière, ordonne la séquence complète des chapitres par ordre réel (chapitres.ordre ASC, id ASC).
     * 4. Identifie le premier chapitre NON_COMMENCE.
     * 5. Recherche le chapitre précédent dans cette séquence ordonnée.
     * 6. Si pas de précédent (1er chapitre) : precedent_titre = null, precedent_termine = null.
     * 7. Si précédent EN_COURS : on ne recommande pas le suivant pour ne pas sauter d'étape.
     * 8. Si précédent TERMINE : on recommande le suivant avec COMMENCER_CHAPITRE.
     */
    protected function computeProchainsChapitres($parcoursMatieres, $progressionsByChapitre): array
    {
        $prochains = [];

        // Parcours ordonné des matières officielles du parcours
        $pmsTries = $parcoursMatieres->sortBy('ordre');

        foreach ($pmsTries as $pm) {
            if (count($prochains) >= 3) {
                break;
            }

            // Séquence ordonnée des chapitres de cette matière selon leur ordre officiel réel
            $chapitresOrdonnes = $pm->chapitres->sortBy(function ($ch) {
                return [$ch->ordre, $ch->id];
            })->values();

            if ($chapitresOrdonnes->isEmpty()) {
                continue;
            }

            // Recherche du premier chapitre NON_COMMENCE dans la séquence
            for ($i = 0; $i < $chapitresOrdonnes->count(); $i++) {
                $chapitreCourant = $chapitresOrdonnes[$i];
                $progCourante = $progressionsByChapitre->get($chapitreCourant->id);
                $statutCourant = $progCourante ? $progCourante->statut : Progression::STATUT_NON_COMMENCE;

                if ($statutCourant === Progression::STATUT_NON_COMMENCE) {
                    // C'est le premier chapitre non commencé de la matière.
                    // Identifions le chapitre précédent dans la séquence ordonnée :
                    if ($i === 0) {
                        // C'est le tout premier chapitre de la matière !
                        $prochains[] = [
                            'chapitre_id' => $chapitreCourant->id,
                            'chapitre_titre' => $chapitreCourant->titre,
                            'matiere' => [
                                'id' => $pm->matiere?->id,
                                'nom' => $pm->matiere?->nom,
                                'code' => $pm->matiere?->code,
                            ],
                            'ordre' => $chapitreCourant->ordre,
                            'chapitre_precedent_titre' => null,
                            'chapitre_precedent_termine' => null, // Exigence 2 : null et non true
                            'raison' => "Premier chapitre officiel de votre programme en {$pm->matiere?->nom}",
                            'action' => 'COMMENCER_CHAPITRE',
                        ];
                        break;
                    } else {
                        // Il y a un chapitre précédent dans la séquence ordonnée
                        $chapitrePrecedent = $chapitresOrdonnes[$i - 1];
                        $progPrecedente = $progressionsByChapitre->get($chapitrePrecedent->id);
                        $statutPrecedent = $progPrecedente ? $progPrecedente->statut : Progression::STATUT_NON_COMMENCE;

                        if ($statutPrecedent === Progression::STATUT_TERMINE) {
                            // Le chapitre précédent est terminé : suite logique recommandée
                            $prochains[] = [
                                'chapitre_id' => $chapitreCourant->id,
                                'chapitre_titre' => $chapitreCourant->titre,
                                'matiere' => [
                                    'id' => $pm->matiere?->id,
                                    'nom' => $pm->matiere?->nom,
                                    'code' => $pm->matiere?->code,
                                ],
                                'ordre' => $chapitreCourant->ordre,
                                'chapitre_precedent_titre' => $chapitrePrecedent->titre,
                                'chapitre_precedent_termine' => true,
                                'raison' => "Chapitre suivant dans votre programme après '{$chapitrePrecedent->titre}'",
                                'action' => 'COMMENCER_CHAPITRE',
                            ];
                            break;
                        } elseif ($statutPrecedent === Progression::STATUT_EN_COURS) {
                            // Exigence 3 : Si le précédent est EN_COURS, ne pas recommander le suivant pour ne pas sauter d'étape.
                            // L'étudiant doit d'abord finaliser le chapitre précédent (déjà accessible dans reprendre_apprentissage).
                            break;
                        }
                    }
                }
            }
        }

        return $prochains;
    }
}
