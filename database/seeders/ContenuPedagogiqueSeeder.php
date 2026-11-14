<?php

namespace Database\Seeders;

use App\Models\Chapitre;
use App\Models\Cours;
use App\Models\Matiere;
use App\Models\Niveau;
use App\Models\ParcoursMatiere;
use App\Models\ParcoursScolaire;
use App\Models\Proposition;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Ressource;
use App\Models\Resume;
use App\Models\Serie;
use App\Models\Transcription;
use App\Models\Video;
use Illuminate\Database\Seeder;

class ContenuPedagogiqueSeeder extends Seeder
{
    public function run(): void
    {
        $terminale = Niveau::where('nom', 'Terminale')->firstOrFail();
        $serieC = Serie::where('code', 'C')->firstOrFail();

        $parcoursTerminaleC = ParcoursScolaire::where('niveau_id', $terminale->id)
            ->where('serie_id', $serieC->id)
            ->firstOrFail();

        // Récupération dynamique des ParcoursMatiere pour les 6 matières de Terminale C
        $matieresNames = [
            'Mathématiques',
            'Physique-Chimie',
            'SVT',
            'Philosophie',
            'Histoire-Géographie',
            'Anglais',
        ];

        $pmByMatiere = [];
        foreach ($matieresNames as $nom) {
            $matiere = Matiere::where('nom', $nom)->firstOrFail();
            $pmByMatiere[$nom] = ParcoursMatiere::where('parcours_id', $parcoursTerminaleC->id)
                ->where('matiere_id', $matiere->id)
                ->firstOrFail();
        }

        $chapitresData = [
            // 1. Mathématiques (Chapitre 1)
            [
                'parcours_matiere_id' => $pmByMatiere['Mathématiques']->id,
                'titre' => 'Limites et continuité des fonctions',
                'description' => 'Calculs de limites, théorème des valeurs intermédiaires et prolongement par continuité.',
                'ordre' => 1,
                'cours' => [
                    'titre' => 'Cours complet : Limites et continuité',
                    'type' => 'TEXTE',
                    'contenu' => "1. Limite d'une fonction en un point et à l'infini.\n2. Continuité sur un intervalle.\n3. Théorème des valeurs intermédiaires (TVI) et bijection.",
                ],
                'resume_cours' => 'Retenir : Une fonction continue sur [a,b] prend toutes les valeurs comprises entre f(a) et f(b) (TVI). Si elle est strictement monotone, elle réalise une bijection.',
                'video' => [
                    'titre' => 'Comprendre le Théorème des Valeurs Intermédiaires (TVI)',
                    'youtube_id' => 'dQw4w9WgXcQ',
                    'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                    'transcription' => 'Dans cette vidéo nous étudions les hypothèses indispensables du théorème des valeurs intermédiaires : la continuité de la fonction f sur un intervalle fermé borné.',
                ],
                'ressources' => [
                    [
                        'titre' => 'Fiche de synthèse - Formes indéterminées et limites',
                        'type' => 'PDF',
                        'url' => 'https://scholarship.test/ressources/maths-limites-fiche.pdf',
                    ],
                    [
                        'titre' => 'Série d\'exercices corrigés sur le TVI',
                        'type' => 'EXERCICES_PDF',
                        'url' => 'https://scholarship.test/ressources/maths-tvi-exercices.pdf',
                    ],
                ],
                'quiz' => [
                    'titre' => 'Quiz d\'évaluation : Limites et continuité',
                    'description' => 'Vérifiez votre maîtrise des limites usuelles et du théorème des valeurs intermédiaires.',
                    'type' => Quiz::TYPE_QUIZ,
                    'origine' => Quiz::ORIGINE_MANUEL,
                    'questions' => [
                        [
                            'enonce' => 'Quelle est la limite de ln(x)/x lorsque x tend vers +infini ?',
                            'type' => 'QCM',
                            'points' => 2.0,
                            'explication' => 'Par croissance comparée, toute puissance positive de x l\'emporte sur ln(x) en +infini, donc ln(x)/x tend vers 0.',
                            'propositions' => [
                                ['contenu' => '0', 'est_correcte' => true],
                                ['contenu' => '+infini', 'est_correcte' => false],
                                ['contenu' => '1', 'est_correcte' => false],
                                ['contenu' => 'Forme indéterminée sans limite', 'est_correcte' => false],
                            ],
                        ],
                        [
                            'enonce' => 'Quelles hypothèses permettent d\'affirmer que l\'équation f(x) = k admet une unique solution sur [a, b] ?',
                            'type' => 'QCM',
                            'points' => 3.0,
                            'explication' => 'C\'est le corollaire du TVI (théorème de la bijection) : continuité, stricte monotonie et k compris entre f(a) et f(b).',
                            'propositions' => [
                                ['contenu' => 'f est continue, strictement monotone sur [a,b] et k est compris entre f(a) et f(b)', 'est_correcte' => true],
                                ['contenu' => 'f est seulement dérivable en a et en b', 'est_correcte' => false],
                                ['contenu' => 'f est positive sur [a, b]', 'est_correcte' => false],
                            ],
                        ],
                    ],
                ],
            ],
            // 1. Mathématiques (Chapitre 2)
            [
                'parcours_matiere_id' => $pmByMatiere['Mathématiques']->id,
                'titre' => 'Nombres complexes',
                'description' => 'Forme algébrique, module, argument, notation exponentielle et applications géométriques.',
                'ordre' => 2,
                'cours' => [
                    'titre' => 'Cours complet : Les nombres complexes en Terminale C',
                    'type' => 'TEXTE',
                    'contenu' => "1. Forme algébrique z = a + ib avec i² = -1.\n2. Conjugué et module |z| = sqrt(a² + b²).\n3. Forme trigonométrique et notation exponentielle z = r * e^(i*theta).\n4. Formules d'Euler et de Moivre.",
                ],
                'resume_cours' => 'Retenir : i² = -1, |z1 * z2| = |z1| * |z2|, arg(z1 * z2) = arg(z1) + arg(z2) [2pi], et e^(i*theta) = cos(theta) + i*sin(theta).',
                'video' => [
                    'titre' => 'Module, argument et forme exponentielle d\'un nombre complexe',
                    'youtube_id' => 'complexes101',
                    'url' => 'https://www.youtube.com/watch?v=complexes101',
                    'transcription' => 'Pour passer de la forme algébrique z = a + ib à la forme exponentielle, on calcule d\'abord le module r = racine de a carré plus b carré.',
                ],
                'ressources' => [
                    [
                        'titre' => 'Formulaire complet sur les nombres complexes',
                        'type' => 'PDF',
                        'url' => 'https://scholarship.test/ressources/maths-complexes-formulaire.pdf',
                    ],
                ],
                'quiz' => [
                    'titre' => 'Entraînement IA : Nombres complexes',
                    'description' => 'Série de questions générées pour réviser le module et la forme exponentielle.',
                    'type' => Quiz::TYPE_REVISION,
                    'origine' => Quiz::ORIGINE_MISTRAL,
                    'questions' => [
                        [
                            'enonce' => 'Quel est le module du nombre complexe z = 1 + i*sqrt(3) ?',
                            'type' => 'QCM',
                            'points' => 2.0,
                            'explication' => '|z| = sqrt(1² + (sqrt(3))²) = sqrt(1 + 3) = sqrt(4) = 2.',
                            'propositions' => [
                                ['contenu' => '2', 'est_correcte' => true],
                                ['contenu' => '4', 'est_correcte' => false],
                                ['contenu' => 'sqrt(2)', 'est_correcte' => false],
                            ],
                        ],
                        [
                            'enonce' => 'Que vaut i² dans l\'ensemble des nombres complexes C ?',
                            'type' => 'QCM',
                            'points' => 1.0,
                            'explication' => 'Par définition de l\'unité imaginaire i, i² = -1.',
                            'propositions' => [
                                ['contenu' => '-1', 'est_correcte' => true],
                                ['contenu' => '1', 'est_correcte' => false],
                                ['contenu' => '0', 'est_correcte' => false],
                            ],
                        ],
                    ],
                ],
            ],
            // 2. Physique-Chimie
            [
                'parcours_matiere_id' => $pmByMatiere['Physique-Chimie']->id,
                'titre' => 'Cinématique et lois de Newton',
                'description' => 'Vecteur position, vitesse, accélération et théorème du centre d\'inertie.',
                'ordre' => 1,
                'cours' => [
                    'titre' => 'Cours complet : Mécanique newtonienne',
                    'type' => 'TEXTE',
                    'contenu' => "1. Référentiel galiléen et repérage.\n2. Vecteur vitesse v = dOM/dt et accélération a = dv/dt.\n3. Deuxième loi de Newton : Somme des forces extérieures = m * a.",
                ],
                'resume_cours' => 'Dans un référentiel galiléen, la somme vectorielle des forces extérieures appliquées à un solide est égale au produit de sa masse par le vecteur accélération de son centre d\'inertie.',
                'video' => [
                    'titre' => 'Appliquer la deuxième loi de Newton (Chute libre et projectile)',
                    'youtube_id' => 'newton2laws',
                    'url' => 'https://www.youtube.com/watch?v=newton2laws',
                    'transcription' => 'Pour étudier le mouvement d\'un projectile dans le champ de pesanteur uniforme, on définit le système, le référentiel terrestre supposé galiléen, puis le bilan des forces.',
                ],
                'ressources' => [
                    [
                        'titre' => 'Méthode de résolution d\'un problème de mécanique',
                        'type' => 'PDF',
                        'url' => 'https://scholarship.test/ressources/physique-newton-methode.pdf',
                    ],
                ],
                'quiz' => [
                    'titre' => 'Quiz : Les 3 lois de Newton',
                    'description' => 'Testez vos connaissances sur le principe d\'inertie et le théorème du centre d\'inertie.',
                    'type' => Quiz::TYPE_EXERCICE,
                    'origine' => Quiz::ORIGINE_MANUEL,
                    'questions' => [
                        [
                            'enonce' => 'Quelle relation traduit la deuxième loi de Newton pour un système de masse constante m ?',
                            'type' => 'QCM',
                            'points' => 2.5,
                            'explication' => 'Le théorème du centre d\'inertie s\'écrit ΣF_ext = m * a_G.',
                            'propositions' => [
                                ['contenu' => 'ΣF_ext = m * a_G', 'est_correcte' => true],
                                ['contenu' => 'ΣF_ext = m * v_G', 'est_correcte' => false],
                                ['contenu' => 'ΣF_ext = 0 dans tous les cas', 'est_correcte' => false],
                            ],
                        ],
                    ],
                ],
            ],
            // 3. SVT
            [
                'parcours_matiere_id' => $pmByMatiere['SVT']->id,
                'titre' => 'Génétique et brassage de l\'information génétique',
                'description' => 'Méiose, brassages interchromosomique et intrachromosomique, et fécondation.',
                'ordre' => 1,
                'cours' => [
                    'titre' => 'Cours complet : Méiose et diversité génétique',
                    'type' => 'TEXTE',
                    'contenu' => "1. Les étapes de la méiose (division réductionnelle et équationnelle).\n2. Brassage intrachromosomique par crossing-over en prophase I.\n3. Brassage interchromosomique en anaphase I.",
                ],
                'resume_cours' => 'La méiose assure le passage de la phase diploïde (2n) à la phase haploïde (n) tout en brassant les allèles parentaux.',
                'video' => [
                    'titre' => 'Comprendre le crossing-over et la méiose en SVT',
                    'youtube_id' => 'svtmeiose01',
                    'url' => 'https://www.youtube.com/watch?v=svtmeiose01',
                    'transcription' => 'Lors de la prophase I de la méiose, les chromosomes homologues s\'apparient et échangent des fragments de chromatides : c\'est le crossing-over.',
                ],
                'ressources' => [
                    [
                        'titre' => 'Schéma bilan de la méiose et du crossing-over',
                        'type' => 'PDF',
                        'url' => 'https://scholarship.test/ressources/svt-meiose-schema.pdf',
                    ],
                ],
                'quiz' => [
                    'titre' => 'Quiz : Méiose et brassage génétique',
                    'description' => 'Évaluez votre compréhension des mécanismes de brassage allélique.',
                    'type' => Quiz::TYPE_QUIZ,
                    'origine' => Quiz::ORIGINE_MANUEL,
                    'questions' => [
                        [
                            'enonce' => 'À quelle étape de la méiose se produit le crossing-over (brassage intrachromosomique) ?',
                            'type' => 'QCM',
                            'points' => 2.0,
                            'explication' => 'L\'appariement des chromosomes homologues et les échanges de fragments de chromatides ont lieu en Prophase I.',
                            'propositions' => [
                                ['contenu' => 'En Prophase I', 'est_correcte' => true],
                                ['contenu' => 'En Anaphase II', 'est_correcte' => false],
                                ['contenu' => 'En Télophase I', 'est_correcte' => false],
                            ],
                        ],
                    ],
                ],
            ],
            // 4. Philosophie
            [
                'parcours_matiere_id' => $pmByMatiere['Philosophie']->id,
                'titre' => 'La conscience et l\'inconscient',
                'description' => 'Le sujet, le cogito cartésien et l\'hypothèse de l\'inconscient psychique chez Freud.',
                'ordre' => 1,
                'cours' => [
                    'titre' => 'Cours complet : Conscience, inconscient et liberté du sujet',
                    'type' => 'TEXTE',
                    'contenu' => "1. Descartes et la certitude du Cogito ('Je pense, donc je suis').\n2. Les illusions de la conscience selon Spinoza et Nietzsche.\n3. La topique freudienne (Ça, Moi, Surmoi) et le refoulement.",
                ],
                'resume_cours' => 'Si la conscience fonde l\'identité du sujet chez Descartes, la psychanalyse freudienne montre que le Moi n\'est pas maître dans sa propre maison.',
                'video' => [
                    'titre' => 'Le Cogito de Descartes et l\'Inconscient de Freud',
                    'youtube_id' => 'philoconsc1',
                    'url' => 'https://www.youtube.com/watch?v=philoconsc1',
                    'transcription' => 'Dans cette vidéo de philosophie, nous confrontons la transparence de la conscience cartésienne à l\'hypothèse freudienne de l\'inconscient.',
                ],
                'ressources' => [
                    [
                        'titre' => 'Fiche de citations : Conscience et Inconscient',
                        'type' => 'PDF',
                        'url' => 'https://scholarship.test/ressources/philo-conscience-citations.pdf',
                    ],
                ],
                'quiz' => [
                    'titre' => 'Quiz : Conscience et inconscient',
                    'description' => 'Vérifiez vos repères philosophiques sur Descartes et Freud.',
                    'type' => Quiz::TYPE_QUIZ,
                    'origine' => Quiz::ORIGINE_MANUEL,
                    'questions' => [
                        [
                            'enonce' => 'Quel philosophe est l\'auteur de la formule "Je pense, donc je suis" (Cogito ergo sum) ?',
                            'type' => 'QCM',
                            'points' => 2.0,
                            'explication' => 'René Descartes formule le Cogito dans le Discours de la méthode (1637).',
                            'propositions' => [
                                ['contenu' => 'René Descartes', 'est_correcte' => true],
                                ['contenu' => 'Sigmund Freud', 'est_correcte' => false],
                                ['contenu' => 'Emmanuel Kant', 'est_correcte' => false],
                            ],
                        ],
                    ],
                ],
            ],
            // 5. Histoire-Géographie
            [
                'parcours_matiere_id' => $pmByMatiere['Histoire-Géographie']->id,
                'titre' => 'Les relations internationales de 1945 à 1991 (La Guerre froide)',
                'description' => 'Formation des blocs Est-Ouest, crises majeures, détente et effondrement de l\'URSS.',
                'ordre' => 1,
                'cours' => [
                    'titre' => 'Cours complet : Du monde bipolaire à la fin de l\'URSS',
                    'type' => 'TEXTE',
                    'contenu' => "1. L'émergence d'un monde bipolaire (1947-1953) : doctrine Truman et doctrine Jdanov.\n2. Les grandes crises : Berlin et Cuba (1962).\n3. De la Détente à la dislocation de l'URSS en décembre 1991.",
                ],
                'resume_cours' => 'La Guerre froide (1947-1991) oppose les États-Unis et l\'URSS dans un affrontement idéologique, géopolitique et économique indirect.',
                'video' => [
                    'titre' => 'Comprendre la Guerre froide et la crise des missiles de Cuba',
                    'youtube_id' => 'histgf1947',
                    'url' => 'https://www.youtube.com/watch?v=histgf1947',
                    'transcription' => 'En octobre 1962, la crise des missiles de Cuba porte la tension entre Washington et Moscou à son paroxysme avant l\'ouverture de la période de Détente.',
                ],
                'ressources' => [
                    [
                        'titre' => 'Frise chronologique de la Guerre froide (1945-1991)',
                        'type' => 'PDF',
                        'url' => 'https://scholarship.test/ressources/hist-guerre-froide-frise.pdf',
                    ],
                ],
                'quiz' => [
                    'titre' => 'Quiz : Les grandes dates de la Guerre froide',
                    'description' => 'Testez votre maîtrise de la chronologie des relations internationales.',
                    'type' => Quiz::TYPE_QUIZ,
                    'origine' => Quiz::ORIGINE_MANUEL,
                    'questions' => [
                        [
                            'enonce' => 'En quelle année a eu lieu la chute du mur de Berlin ?',
                            'type' => 'QCM',
                            'points' => 2.0,
                            'explication' => 'Le mur de Berlin est tombé dans la nuit du 9 novembre 1989.',
                            'propositions' => [
                                ['contenu' => '1989', 'est_correcte' => true],
                                ['contenu' => '1961', 'est_correcte' => false],
                                ['contenu' => '1991', 'est_correcte' => false],
                            ],
                        ],
                    ],
                ],
            ],
            // 6. Anglais
            [
                'parcours_matiere_id' => $pmByMatiere['Anglais']->id,
                'titre' => 'Science, Technology and Social Change',
                'description' => 'Expressing cause, consequence, hypothesis, and debating artificial intelligence in English.',
                'ordre' => 1,
                'cours' => [
                    'titre' => 'Complete Lesson: Conditional Sentences and Linking Words',
                    'type' => 'TEXTE',
                    'contenu' => "1. First, Second and Third Conditionals.\n2. Expressing cause (because of, owing to, due to) and consequence (therefore, consequently).\n3. Vocabulary of innovation and ethics.",
                ],
                'resume_cours' => 'Remember: Type 2 conditional uses If + Past Simple -> would + base verb. Type 3 conditional uses If + Past Perfect -> would have + past participle.',
                'video' => [
                    'titre' => 'Mastering English Conditionals (If Clauses) for Final Exams',
                    'youtube_id' => 'engcond001',
                    'url' => 'https://www.youtube.com/watch?v=engcond001',
                    'transcription' => 'In this English lesson, we review the three main conditional structures used to express probability, unreal present, and past regrets.',
                ],
                'ressources' => [
                    [
                        'titre' => 'Grammar Sheet: Linking Words and Conditionals',
                        'type' => 'PDF',
                        'url' => 'https://scholarship.test/ressources/anglais-conditionals-sheet.pdf',
                    ],
                ],
                'quiz' => [
                    'titre' => 'Quiz: Conditional Sentences & Linking Words',
                    'description' => 'Check your grammar skills on English conditionals.',
                    'type' => Quiz::TYPE_QUIZ,
                    'origine' => Quiz::ORIGINE_MANUEL,
                    'questions' => [
                        [
                            'enonce' => 'Choose the correct form: "If she had studied harder, she ___ the exam."',
                            'type' => 'QCM',
                            'points' => 2.0,
                            'explication' => 'Third conditional (past unreal): If + Past Perfect (had studied) -> would have + past participle (would have passed).',
                            'propositions' => [
                                ['contenu' => 'would have passed', 'est_correcte' => true],
                                ['contenu' => 'will pass', 'est_correcte' => false],
                                ['contenu' => 'would pass', 'est_correcte' => false],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($chapitresData as $cData) {
            $chapitre = Chapitre::updateOrCreate(
                [
                    'parcours_matiere_id' => $cData['parcours_matiere_id'],
                    'titre' => $cData['titre'],
                ],
                [
                    'description' => $cData['description'],
                    'ordre' => $cData['ordre'],
                ]
            );

            // Cours
            $cours = Cours::updateOrCreate(
                [
                    'chapitre_id' => $chapitre->id,
                    'titre' => $cData['cours']['titre'],
                ],
                [
                    'type' => $cData['cours']['type'],
                    'contenu' => $cData['cours']['contenu'],
                    'date_creation' => now(),
                ]
            );

            // Résumé associé au cours (cours_id renseigné, transcription_id null)
            Resume::updateOrCreate(
                [
                    'cours_id' => $cours->id,
                    'transcription_id' => null,
                ],
                [
                    'contenu' => $cData['resume_cours'],
                    'date_creation' => now(),
                ]
            );

            // Vidéo & Transcription
            $video = Video::updateOrCreate(
                [
                    'chapitre_id' => $chapitre->id,
                    'titre' => $cData['video']['titre'],
                ],
                [
                    'youtube_id' => $cData['video']['youtube_id'],
                    'url' => $cData['video']['url'],
                ]
            );

            $transcription = Transcription::updateOrCreate(
                ['video_id' => $video->id],
                [
                    'contenu' => $cData['video']['transcription'],
                    'date_creation' => now(),
                ]
            );

            // Résumé associé à la transcription (cours_id null, transcription_id renseigné)
            Resume::updateOrCreate(
                [
                    'cours_id' => null,
                    'transcription_id' => $transcription->id,
                ],
                [
                    'contenu' => 'Synthèse vidéo : ' . $cData['video']['transcription'],
                    'date_creation' => now(),
                ]
            );

            // Ressources
            foreach ($cData['ressources'] as $rData) {
                Ressource::updateOrCreate(
                    [
                        'chapitre_id' => $chapitre->id,
                        'titre' => $rData['titre'],
                    ],
                    [
                        'type' => $rData['type'],
                        'url' => $rData['url'],
                    ]
                );
            }

            // Quiz, Questions et Propositions
            $quizData = $cData['quiz'];
            $quiz = Quiz::updateOrCreate(
                [
                    'chapitre_id' => $chapitre->id,
                    'titre' => $quizData['titre'],
                ],
                [
                    'description' => $quizData['description'],
                    'type' => $quizData['type'],
                    'origine' => $quizData['origine'],
                    'date_creation' => now(),
                ]
            );

            foreach ($quizData['questions'] as $qData) {
                $question = Question::updateOrCreate(
                    [
                        'quiz_id' => $quiz->id,
                        'enonce' => $qData['enonce'],
                    ],
                    [
                        'type' => $qData['type'],
                        'points' => $qData['points'],
                        'explication' => $qData['explication'],
                    ]
                );

                foreach ($qData['propositions'] as $pData) {
                    Proposition::updateOrCreate(
                        [
                            'question_id' => $question->id,
                            'contenu' => $pData['contenu'],
                        ],
                        [
                            'est_correcte' => $pData['est_correcte'],
                        ]
                    );
                }
            }
        }
    }
}
