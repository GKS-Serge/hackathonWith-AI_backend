<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapitre;
use App\Models\ParcoursMatiere;
use App\Models\Video;
use App\Services\YouTubeService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VideoController extends Controller
{
    protected YouTubeService $youtubeService;

    public function __construct(YouTubeService $youtubeService)
    {
        $this->youtubeService = $youtubeService;
    }

    /**
     * Recherche de vidéos pédagogiques YouTube avec ciblage contextuel (matière, niveau, série, chapitre).
     * Accessible aux étudiants connectés et aux enseignants.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'nullable|string|max:100',
            'chapitre_id' => 'nullable|integer|exists:chapitres,id',
            'max_results' => 'nullable|integer|min:1|max:15',
        ]);

        $chapitreId = $request->query('chapitre_id');
        $context = [];
        $defaultQuery = $request->query('q', '');

        if ($chapitreId) {
            $chapitre = Chapitre::with(['parcoursMatiere.parcours.niveau', 'parcoursMatiere.parcours.serie', 'parcoursMatiere.matiere'])->find($chapitreId);
            if ($chapitre) {
                // Vérification de sécurité : si l'étudiant a un parcours, s'assurer que le chapitre appartient à son parcours
                $etudiant = $request->user();
                if ($etudiant && $etudiant->parcours_id && $chapitre->parcoursMatiere->parcours_id !== $etudiant->parcours_id) {
                    return response()->json([
                        'message' => 'Ce chapitre ne fait pas partie de votre parcours scolaire.',
                    ], 403);
                }

                $context['chapitre'] = $chapitre->titre;
                $context['matiere'] = $chapitre->parcoursMatiere->matiere->nom ?? '';
                $context['niveau'] = $chapitre->parcoursMatiere->parcours->niveau->nom ?? '';
                $context['serie'] = $chapitre->parcoursMatiere->parcours->serie->nom ?? '';

                if (empty($defaultQuery)) {
                    $defaultQuery = "cours " . ($context['matiere'] ? $context['matiere'] . ' ' : '') . $chapitre->titre;
                }
            }
        }

        if (empty($defaultQuery)) {
            $defaultQuery = "cours enseignement secondaire Togo";
        }

        $maxResults = (int) $request->query('max_results', 6);

        try {
            $videos = $this->youtubeService->searchEducationalVideos($defaultQuery, $maxResults, $context);

            return response()->json([
                'query' => $defaultQuery,
                'context' => $context,
                'total' => count($videos),
                'videos' => $videos,
            ]);
        } catch (Exception $e) {
            Log::error('Erreur recherche YouTube: ' . $e->getMessage());
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
            return response()->json([
                'message' => 'Impossible de récupérer les vidéos YouTube pour le moment.',
                'erreur' => $e->getMessage(),
            ], $code);
        }
    }

    /**
     * Récupère les métadonnées vérifiées d'une vidéo YouTube via son ID.
     */
    public function details(Request $request): JsonResponse
    {
        $request->validate([
            'youtube_id' => 'required|string|size:11',
        ]);

        $youtubeId = $request->query('youtube_id');

        try {
            $details = $this->youtubeService->getVideoDetails($youtubeId);

            if (!$details) {
                return response()->json([
                    'message' => 'Vidéo introuvable sur YouTube ou inaccessible.',
                ], 404);
            }

            return response()->json([
                'video' => $details,
            ]);
        } catch (Exception $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
            return response()->json([
                'message' => 'Erreur lors de la récupération des détails YouTube.',
                'erreur' => $e->getMessage(),
            ], $code);
        }
    }

    /**
     * Association sécurisée d'une vidéo YouTube sélectionnée à un chapitre du catalogue.
     * Accessible uniquement via un token d'autorisation backend ou rôle autorisé.
     */
    public function lierVideoChapitre(Request $request, Chapitre $chapitre): JsonResponse
    {
        $request->validate([
            'youtube_id' => 'required|string|size:11',
            'titre' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        // Vérification de sécurité : un étudiant normal ne peut pas modifier le catalogue pédagogique
        $user = $request->user();
        if ($user && method_exists($user, 'isEtudiant') && $user->isEtudiant()) {
            return response()->json([
                'message' => 'Action non autorisée. Les étudiants ne peuvent pas associer de vidéos au catalogue.',
            ], 403);
        }

        $youtubeId = $request->input('youtube_id');

        // Vérification anti-doublon pour ce chapitre
        $existing = Video::where('chapitre_id', $chapitre->id)
            ->where('youtube_id', $youtubeId)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Cette vidéo est déjà associée à ce chapitre.',
                'video' => $existing,
            ], 200);
        }

        try {
            // Récupération des métadonnées officielles depuis YouTube Data API v3
            $details = $this->youtubeService->getVideoDetails($youtubeId);

            if (!$details) {
                return response()->json([
                    'message' => 'Vidéo introuvable sur YouTube ou inaccessible.',
                ], 404);
            }

            if (!$details['est_integrable']) {
                return response()->json([
                    'message' => "Cette vidéo ne peut pas être intégrée dans SCHOLARSHIP (l'auteur a restreint l'intégration ou elle est privée).",
                    'details' => $details,
                ], 422);
            }

            $titre = $request->input('titre') ?: $details['titre'];
            $description = $request->input('description') ?: $details['description'];

            $video = Video::create([
                'chapitre_id' => $chapitre->id,
                'titre' => $titre,
                'description' => $description,
                'youtube_id' => $youtubeId,
                'url' => $details['url'],
                'duree' => $details['duree'],
                'duree_secondes' => $details['duree_secondes'],
                'thumbnail_url' => $details['thumbnail_url'],
                'chaine_titre' => $details['chaine_titre'],
                'est_integrable' => true,
                'statut_integration' => 'VALIDATED',
                'published_at' => $details['published_at'],
            ]);

            return response()->json([
                'message' => 'Vidéo associée avec succès au chapitre.',
                'video' => $video,
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'message' => "Erreur lors de l'enregistrement de la vidéo.",
                'erreur' => $e->getMessage(),
            ], 500);
        }
    }
}
