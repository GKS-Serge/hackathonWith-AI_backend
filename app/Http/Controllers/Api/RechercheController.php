<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapitre;
use App\Models\Cours;
use App\Models\ParcoursMatiere;
use App\Models\Quiz;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RechercheController extends Controller
{
    /**
     * Recherche globale pédagogique (Omnisearch) cloisonnée par le parcours de l'étudiant connecté.
     * Couvre simultanément les chapitres, les cours écrits, les vidéos intégrables et les quiz.
     */
    public function rechercher(Request $request): JsonResponse
    {
        $etudiant = $request->user();

        // 1. Validation rigoureuse des paramètres d'entrée
        $validator = Validator::make($request->all(), [
            'q' => 'required|string|min:2|max:80',
            'filtre' => 'nullable|string|in:all,chapitres,cours,videos,quizzes',
            'limit' => 'nullable|integer|min:1|max:30',
        ], [
            'q.required' => 'Le terme de recherche est obligatoire.',
            'q.min' => 'Le terme de recherche doit contenir au moins 2 caractères.',
            'q.max' => 'Le terme de recherche ne peut pas dépasser 80 caractères.',
            'filtre.in' => 'Le filtre spécifié est invalide (valeurs acceptées : all, chapitres, cours, videos, quizzes).',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Paramètres de recherche invalides.',
                'code' => 'VALIDATION_ERROR',
                'erreurs' => $validator->errors(),
            ], 422);
        }

        $rawQuery = trim((string) $request->query('q'));
        $filtre = (string) $request->query('filtre', 'all');
        $limit = (int) $request->query('limit', 5);

        // 2. Détermination stricte du parcours scolaire de l'étudiant
        $parcoursId = $etudiant->parcours_id;
        if (! $parcoursId) {
            return response()->json([
                'message' => "Votre compte étudiant n'est rattaché à aucun parcours scolaire.",
                'code' => 'PARCOURS_NOT_FOUND',
            ], 403);
        }

        // Récupération des IDs des chapitres appartenant EXCLUSIVEMENT au parcours de l'étudiant
        $parcoursMatiereIds = ParcoursMatiere::where('parcours_id', $parcoursId)->pluck('id');
        $allowedChapitreIds = Chapitre::whereIn('parcours_matiere_id', $parcoursMatiereIds)->pluck('id');

        // 3. Construction de la Regex insensible aux accents et à la casse
        $regexPattern = $this->buildAccentInsensitiveRegex($rawQuery);

        $resultats = [
            'chapitres' => [],
            'cours' => [],
            'videos' => [],
            'quizzes' => [],
        ];

        // 4. Recherche dans les Chapitres
        if (in_array($filtre, ['all', 'chapitres'], true)) {
            $chapitres = Chapitre::with(['parcoursMatiere.matiere'])
                ->whereIn('id', $allowedChapitreIds)
                ->where(function ($q) use ($regexPattern) {
                    $q->whereRaw('titre ~* ?', [$regexPattern])
                      ->orWhereRaw('description ~* ?', [$regexPattern]);
                })
                ->orderBy('ordre')
                ->limit($limit)
                ->get()
                ->map(function ($chap) {
                    return [
                        'id' => $chap->id,
                        'titre' => $chap->titre,
                        'description' => $chap->description,
                        'position' => $chap->ordre,
                        'est_demo' => (bool) $chap->est_demo,
                        'matiere' => [
                            'id' => $chap->parcoursMatiere?->matiere?->id,
                            'nom' => $chap->parcoursMatiere?->matiere?->nom,
                            'code' => $chap->parcoursMatiere?->matiere?->code,
                        ],
                    ];
                })
                ->values()
                ->all();

            $resultats['chapitres'] = $chapitres;
        }

        // 5. Recherche dans les Cours
        if (in_array($filtre, ['all', 'cours'], true)) {
            $cours = Cours::with(['chapitre.parcoursMatiere.matiere', 'resumes'])
                ->whereIn('chapitre_id', $allowedChapitreIds)
                ->where(function ($q) use ($regexPattern) {
                    $q->whereRaw('titre ~* ?', [$regexPattern])
                      ->orWhereRaw('contenu ~* ?', [$regexPattern]);
                })
                ->orderBy('id')
                ->limit($limit)
                ->get()
                ->map(function ($c) {
                    return [
                        'id' => $c->id,
                        'titre' => $c->titre,
                        'chapitre_id' => $c->chapitre_id,
                        'chapitre_titre' => $c->chapitre?->titre,
                        'matiere_nom' => $c->chapitre?->parcoursMatiere?->matiere?->nom,
                        'temps_lecture_estime' => max(5, (int) ceil(str_word_count(strip_tags((string) $c->contenu)) / 200)),
                    ];
                })
                ->values()
                ->all();

            $resultats['cours'] = $cours;
        }

        // 6. Recherche dans les Vidéos intégrables
        if (in_array($filtre, ['all', 'videos'], true)) {
            $videos = Video::with(['chapitre.parcoursMatiere.matiere', 'transcription'])
                ->whereIn('chapitre_id', $allowedChapitreIds)
                ->where('est_integrable', true)
                ->where(function ($q) use ($regexPattern) {
                    $q->whereRaw('titre ~* ?', [$regexPattern])
                      ->orWhereRaw('description ~* ?', [$regexPattern])
                      ->orWhereHas('transcription', function ($tq) use ($regexPattern) {
                          $tq->whereRaw('contenu ~* ?', [$regexPattern]);
                      });
                })
                ->orderBy('id')
                ->limit($limit)
                ->get()
                ->map(function ($v) {
                    $hasTranscription = $v->transcription !== null && !empty($v->transcription->contenu);
                    return [
                        'id' => $v->id,
                        'titre' => $v->titre,
                        'chapitre_id' => $v->chapitre_id,
                        'chapitre_titre' => $v->chapitre?->titre,
                        'matiere_nom' => $v->chapitre?->parcoursMatiere?->matiere?->nom,
                        'duree' => $v->duree ?? '00:00',
                        'thumbnail_url' => $v->thumbnail_url,
                        'embed_url' => $v->embed_url,
                        'a_transcription' => $hasTranscription,
                        'statut_transcription' => $hasTranscription ? 'DISPONIBLE' : 'NON_DISPONIBLE_POUR_LE_MOMENT',
                    ];
                })
                ->values()
                ->all();

            $resultats['videos'] = $videos;
        }

        // 7. Recherche dans les Quiz (sans exposer aucune bonne réponse)
        if (in_array($filtre, ['all', 'quizzes'], true)) {
            $quizzes = Quiz::with(['chapitre.parcoursMatiere.matiere', 'questions'])
                ->whereIn('chapitre_id', $allowedChapitreIds)
                ->where(function ($q) use ($regexPattern) {
                    $q->whereRaw('titre ~* ?', [$regexPattern])
                      ->orWhereRaw('description ~* ?', [$regexPattern]);
                })
                ->orderBy('id')
                ->limit($limit)
                ->get()
                ->map(function ($qz) {
                    return [
                        'id' => $qz->id,
                        'titre' => $qz->titre,
                        'description' => $qz->description,
                        'chapitre_id' => $qz->chapitre_id,
                        'chapitre_titre' => $qz->chapitre?->titre,
                        'matiere_nom' => $qz->chapitre?->parcoursMatiere?->matiere?->nom,
                        'type' => $qz->type,
                        'nombre_questions' => $qz->questions->count(),
                    ];
                })
                ->values()
                ->all();

            $resultats['quizzes'] = $quizzes;
        }

        // 8. Calcul exact du total des résultats retournés
        $totalResultats = count($resultats['chapitres'])
            + count($resultats['cours'])
            + count($resultats['videos'])
            + count($resultats['quizzes']);

        // Informations synthétiques sur le parcours de l'étudiant
        $parcoursScolaire = $etudiant->parcoursScolaire;

        return response()->json([
            'query' => $rawQuery,
            'filtre' => $filtre,
            'parcours_etudiant' => [
                'id' => $parcoursScolaire?->id,
                'nom' => $parcoursScolaire?->nom,
                'niveau' => $parcoursScolaire?->niveau?->nom,
                'serie' => $parcoursScolaire?->serie?->nom,
            ],
            'total_resultats' => $totalResultats,
            'resultats' => $resultats,
        ]);
    }

    /**
     * Construit une expression régulière POSIX PostgreSQL insensible aux accents et à la casse,
     * tout en échappant les caractères spéciaux d'expression régulière pour éviter les injections.
     */
    protected function buildAccentInsensitiveRegex(string $term): string
    {
        // Table de correspondance bi-directionnelle : chaque voyelle (accentuée ou non) matche toutes ses variantes
        $accentMap = [
            'a' => '[aàáâãäåÀÁÂÃÄÅ]',
            'à' => '[aàáâãäåÀÁÂÃÄÅ]',
            'á' => '[aàáâãäåÀÁÂÃÄÅ]',
            'â' => '[aàáâãäåÀÁÂÃÄÅ]',
            'ã' => '[aàáâãäåÀÁÂÃÄÅ]',
            'ä' => '[aàáâãäåÀÁÂÃÄÅ]',
            'e' => '[eèéêëÈÉÊË]',
            'è' => '[eèéêëÈÉÊË]',
            'é' => '[eèéêëÈÉÊË]',
            'ê' => '[eèéêëÈÉÊË]',
            'ë' => '[eèéêëÈÉÊË]',
            'i' => '[iìíîïÌÍÎÏ]',
            'ì' => '[iìíîïÌÍÎÏ]',
            'í' => '[iìíîïÌÍÎÏ]',
            'î' => '[iìíîïÌÍÎÏ]',
            'ï' => '[iìíîïÌÍÎÏ]',
            'o' => '[oòóôõöøÒÓÔÕÖØ]',
            'ò' => '[oòóôõöøÒÓÔÕÖØ]',
            'ó' => '[oòóôõöøÒÓÔÕÖØ]',
            'ô' => '[oòóôõöøÒÓÔÕÖØ]',
            'õ' => '[oòóôõöøÒÓÔÕÖØ]',
            'ö' => '[oòóôõöøÒÓÔÕÖØ]',
            'u' => '[uùúûüÙÚÛÜ]',
            'ù' => '[uùúûüÙÚÛÜ]',
            'ú' => '[uùúûüÙÚÛÜ]',
            'û' => '[uùúûüÙÚÛÜ]',
            'ü' => '[uùúûüÙÚÛÜ]',
            'c' => '[cçCÇ]',
            'ç' => '[cçCÇ]',
            'n' => '[nñNÑ]',
            'ñ' => '[nñNÑ]',
        ];

        // Découpage propre en caractères UTF-8
        $chars = mb_str_split(mb_strtolower($term, 'UTF-8'));
        $regex = '';

        foreach ($chars as $char) {
            if (isset($accentMap[$char])) {
                $regex .= $accentMap[$char];
            } else {
                // Échappement des métacaractères Regex (\, ., +, *, ?, [, ], (, ), {, }, ^, $, |, -)
                $regex .= preg_quote($char, '/');
            }
        }

        return $regex;
    }
}
