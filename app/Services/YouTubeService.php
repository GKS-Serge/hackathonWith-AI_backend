<?php

namespace App\Services;

use DateInterval;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeService
{
    protected ?string $apiKey;
    protected string $baseUrl;
    protected int $timeout;
    protected int $cacheTtlSearch;
    protected int $cacheTtlDetails;

    public function __construct()
    {
        $this->apiKey = config('services.youtube.key');
        $this->baseUrl = rtrim(config('services.youtube.base_url', 'https://www.googleapis.com/youtube/v3'), '/');
        $this->timeout = (int) config('services.youtube.timeout', 15);
        $this->cacheTtlSearch = (int) config('services.youtube.cache_ttl_search', 86400); // 24h
        $this->cacheTtlDetails = (int) config('services.youtube.cache_ttl_details', 604800); // 7 jours
    }

    /**
     * Vérifie si la clé API est configurée.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && is_string($this->apiKey);
    }

    /**
     * Recherche de vidéos pédagogiques ciblées sur YouTube avec mise en cache et enrichissement des métadonnées.
     *
     * @param string $query Terme de recherche
     * @param int $maxResults Nombre max de résultats (1 à 25)
     * @param array $context Filtres optionnels: ['niveau' => ..., 'serie' => ..., 'matiere' => ..., 'chapitre' => ...]
     * @return array Liste des vidéos enrichies et vérifiées
     */
    public function searchEducationalVideos(string $query, int $maxResults = 8, array $context = []): array
    {
        if (!$this->isConfigured()) {
            throw new Exception("La clé YouTube Data API (YOUTUBE_API_KEY) n'est pas configurée dans le backend.");
        }

        $maxResults = max(1, min(25, $maxResults));

        // Construction d'une requête contextuelle optimisée pour les cours scolaires
        $refinedTerms = [];
        if (!empty($context['matiere'])) {
            $refinedTerms[] = $context['matiere'];
        }
        if (!empty($context['niveau'])) {
            $refinedTerms[] = $context['niveau'];
        }
        if (!empty($context['serie'])) {
            $refinedTerms[] = 'série ' . $context['serie'];
        }
        if (!empty($context['chapitre'])) {
            $refinedTerms[] = $context['chapitre'];
        }

        $fullQuery = trim($query);
        if (!empty($refinedTerms) && !str_contains(strtolower($fullQuery), strtolower($refinedTerms[0]))) {
            $fullQuery = implode(' ', $refinedTerms) . ' ' . $fullQuery;
        }

        $cacheKey = 'youtube_search_' . md5($fullQuery . '_' . $maxResults);

        return Cache::remember($cacheKey, $this->cacheTtlSearch, function () use ($fullQuery, $maxResults) {
            $response = Http::timeout($this->timeout)->get("{$this->baseUrl}/search", [
                'part' => 'snippet',
                'q' => $fullQuery,
                'type' => 'video',
                'maxResults' => $maxResults,
                'videoEmbeddable' => 'true', // Exclut d'emblée les vidéos dont les créateurs interdisent l'intégration
                'safeSearch' => 'strict',
                'relevanceLanguage' => 'fr',
                'key' => $this->apiKey,
            ]);

            if ($response->failed()) {
                $this->handleApiError($response, 'search');
            }

            $items = $response->json('items', []);
            $videoIds = [];

            foreach ($items as $item) {
                if (isset($item['id']['videoId'])) {
                    $videoIds[] = $item['id']['videoId'];
                }
            }

            if (empty($videoIds)) {
                return [];
            }

            // Récupération en batch des métadonnées complètes (durée, intégrabilité stricte, statut de confidentialité)
            return $this->getMultipleVideoDetails($videoIds);
        });
    }

    /**
     * Récupère les métadonnées détaillées d'une vidéo YouTube par son ID avec mise en cache.
     */
    public function getVideoDetails(string $youtubeId): ?array
    {
        if (!$this->isConfigured()) {
            throw new Exception("La clé YouTube Data API (YOUTUBE_API_KEY) n'est pas configurée.");
        }

        $youtubeId = trim($youtubeId);
        if (empty($youtubeId)) {
            return null;
        }

        $cacheKey = 'youtube_details_' . $youtubeId;

        return Cache::remember($cacheKey, $this->cacheTtlDetails, function () use ($youtubeId) {
            $detailsList = $this->getMultipleVideoDetails([$youtubeId]);
            return $detailsList[0] ?? null;
        });
    }

    /**
     * Récupère les détails de plusieurs vidéos en une seule requête batch vers YouTube Data API.
     */
    public function getMultipleVideoDetails(array $youtubeIds): array
    {
        if (empty($youtubeIds) || !$this->isConfigured()) {
            return [];
        }

        $idString = implode(',', array_slice(array_unique($youtubeIds), 0, 50));

        $response = Http::timeout($this->timeout)->get("{$this->baseUrl}/videos", [
            'part' => 'snippet,contentDetails,status',
            'id' => $idString,
            'key' => $this->apiKey,
        ]);

        if ($response->failed()) {
            $this->handleApiError($response, 'videos');
        }

        $items = $response->json('items', []);
        $formattedVideos = [];

        foreach ($items as $item) {
            $formatted = $this->formatVideoItem($item);
            if ($formatted !== null) {
                $formattedVideos[] = $formatted;
            }
        }

        return $formattedVideos;
    }

    /**
     * Formate un élément renvoyé par l'API YouTube et valide son intégrabilité.
     */
    protected function formatVideoItem(array $item): ?array
    {
        $id = $item['id'] ?? null;
        if (!$id) {
            return null;
        }

        $snippet = $item['snippet'] ?? [];
        $contentDetails = $item['contentDetails'] ?? [];
        $status = $item['status'] ?? [];

        $privacyStatus = $status['privacyStatus'] ?? 'public';
        $embeddable = (bool) ($status['embeddable'] ?? true);

        // Une vidéo doit être publique (ou non répertoriée) et intégrable
        $isAccessible = ($privacyStatus !== 'private') && $embeddable;

        $isoDuration = $contentDetails['duration'] ?? 'PT0S';
        $seconds = $this->parseIsoDurationToSeconds($isoDuration);
        $formattedDuration = $this->formatSecondsToHumanTime($seconds);

        $thumbnails = $snippet['thumbnails'] ?? [];
        $thumbnailUrl = $thumbnails['maxres']['url']
            ?? $thumbnails['standard']['url']
            ?? $thumbnails['high']['url']
            ?? $thumbnails['medium']['url']
            ?? $thumbnails['default']['url']
            ?? "https://img.youtube.com/vi/{$id}/hqdefault.jpg";

        return [
            'youtube_id' => $id,
            'titre' => $snippet['title'] ?? 'Vidéo pédagogique',
            'description' => $snippet['description'] ?? '',
            'chaine_titre' => $snippet['channelTitle'] ?? '',
            'published_at' => $snippet['publishedAt'] ?? null,
            'duree_iso' => $isoDuration,
            'duree' => $formattedDuration,
            'duree_secondes' => $seconds,
            'thumbnail_url' => $thumbnailUrl,
            'est_integrable' => $isAccessible,
            'statut_confidentialite' => $privacyStatus,
            'url' => "https://www.youtube.com/watch?v={$id}",
            'embed_url' => "https://www.youtube-nocookie.com/embed/{$id}?rel=0&modestbranding=1",
        ];
    }

    /**
     * Convertit une durée ISO 8601 (ex: PT15M33S, PT1H2M10S) en nombre total de secondes.
     */
    public function parseIsoDurationToSeconds(string $isoDuration): int
    {
        try {
            $interval = new DateInterval($isoDuration);
            return ($interval->d * 86400) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Formate un nombre de secondes en chaîne lisible MM:SS ou HH:MM:SS.
     */
    public function formatSecondsToHumanTime(int $seconds): string
    {
        if ($seconds <= 0) {
            return '00:00';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%02d:%02d', $minutes, $secs);
    }

    /**
     * Gère les erreurs spécifiques retournées par YouTube Data API v3 (ex: quota).
     */
    protected function handleApiError($response, string $action): void
    {
        $status = $response->status();
        $errorBody = $response->json('error', []);
        $message = $errorBody['message'] ?? 'Erreur inconnue YouTube API';
        $reason = $errorBody['errors'][0]['reason'] ?? 'unknown';

        Log::error("YouTube API Error on {$action} [{$status}]: {$message}", [
            'reason' => $reason,
            'body' => $response->body(),
        ]);

        if ($status === 403 && (str_contains($reason, 'quota') || str_contains($reason, 'rateLimitExceeded'))) {
            throw new Exception("Le quota journalier de l'API YouTube Data v3 est temporairement atteint. Veuillez réessayer ultérieurement.", 429);
        }

        if ($status === 400 && str_contains($reason, 'keyInvalid')) {
            throw new Exception("La clé YouTube Data API configurée est invalide.", 400);
        }

        throw new Exception("Erreur YouTube Data API ({$status}): {$message}", $status);
    }
}
