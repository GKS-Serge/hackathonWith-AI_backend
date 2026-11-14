<?php

namespace App\Services\Ai;

use App\Contracts\PedagogicalAiInterface;
use App\Exceptions\AiServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService implements PedagogicalAiInterface
{
    public function __construct(
        protected PedagogicalPromptBuilder $promptBuilder
    ) {
    }

    /**
     * Génère un résumé pédagogique structuré à partir d'un cours ou d'une transcription vidéo.
     */
    public function generateResume(
        string $sourceType,
        string $titre,
        string $contenu,
        string $niveau,
        string $serie
    ): string {
        $systemInstruction = $this->promptBuilder->buildSystemInstruction($niveau, $serie);
        $userPrompt = $this->promptBuilder->buildResumePrompt($sourceType, $titre, $contenu);

        $data = $this->callGeminiJson($systemInstruction, $userPrompt, 0.2);

        $resume = isset($data['resume']) ? trim((string) $data['resume']) : '';
        if ($resume === '') {
            throw new AiServiceException("Le résumé généré par Gemini est vide ou invalide.", 502);
        }

        if (! empty($data['points_cles']) && is_array($data['points_cles'])) {
            $points = array_filter(array_map(fn ($p) => is_string($p) ? trim($p) : '', $data['points_cles']));
            if (! empty($points)) {
                $resume .= "\n\nPoints clés à retenir :\n- " . implode("\n- ", $points);
            }
        }

        return $resume;
    }

    /**
     * Explique une notion ou un passage d'un cours en distinguant le contenu source et les compléments explicatifs.
     */
    public function explainConcept(
        string $titreCours,
        string $contenuCours,
        string $questionOuNotion,
        string $niveau,
        string $serie
    ): array {
        $systemInstruction = $this->promptBuilder->buildSystemInstruction($niveau, $serie);
        $userPrompt = $this->promptBuilder->buildExplicationPrompt($titreCours, $contenuCours, $questionOuNotion);

        $data = $this->callGeminiJson($systemInstruction, $userPrompt, 0.3);

        $elementsDuCours = isset($data['elements_issus_du_cours']) ? trim((string) $data['elements_issus_du_cours']) : '';
        $explication = isset($data['explication_pedagogique']) ? trim((string) $data['explication_pedagogique']) : '';

        if ($elementsDuCours === '' || $explication === '') {
            throw new AiServiceException("L'explication générée par Gemini est incomplète.", 502);
        }

        $exemples = [];
        if (! empty($data['exemples_pedagogiques']) && is_array($data['exemples_pedagogiques'])) {
            foreach ($data['exemples_pedagogiques'] as $ex) {
                if (is_string($ex) && trim($ex) !== '') {
                    $exemples[] = trim($ex);
                }
            }
        }

        return [
            'notion_demandee' => $questionOuNotion,
            'elements_issus_du_cours' => $elementsDuCours,
            'explication_pedagogique' => $explication,
            'exemples_pedagogiques' => $exemples,
            'a_retenir' => isset($data['a_retenir']) ? trim((string) $data['a_retenir']) : '',
        ];
    }

    /**
     * Génère et valide un quiz QCM (QUIZ ou REVISION).
     */
    public function generateQuiz(
        string $titreChapitre,
        string $contenuSource,
        string $type,
        int $nbQuestions,
        string $niveau,
        string $serie
    ): array {
        $systemInstruction = $this->promptBuilder->buildSystemInstruction($niveau, $serie);
        $userPrompt = $this->promptBuilder->buildQuizPrompt($titreChapitre, $contenuSource, $type, $nbQuestions);

        $data = $this->callGeminiJson($systemInstruction, $userPrompt, 0.3);

        return $this->validateGeneratedQuizOrExercice($data, $titreChapitre, false);
    }

    /**
     * Génère et valide un exercice progressif adapté au chapitre et au niveau scolaire.
     */
    public function generateExercice(
        string $titreChapitre,
        string $contenuSource,
        int $nbQuestions,
        string $niveau,
        string $serie
    ): array {
        $systemInstruction = $this->promptBuilder->buildSystemInstruction($niveau, $serie);
        $userPrompt = $this->promptBuilder->buildExercicePrompt($titreChapitre, $contenuSource, $nbQuestions);

        $data = $this->callGeminiJson($systemInstruction, $userPrompt, 0.3);

        return $this->validateGeneratedQuizOrExercice($data, $titreChapitre, true);
    }

    /**
     * Évalue les réponses textuelles libres d'un étudiant selon le barème et plafonne les notes côté Laravel.
     */
    public function evaluateOpenResponses(
        string $titreQuiz,
        string $contenuSource,
        array $questionsEtReponses,
        string $niveau,
        string $serie
    ): array {
        $systemInstruction = $this->promptBuilder->buildSystemInstruction($niveau, $serie);
        $userPrompt = $this->promptBuilder->buildEvaluationPrompt($titreQuiz, $contenuSource, $questionsEtReponses);

        $data = $this->callGeminiJson($systemInstruction, $userPrompt, 0.1);

        if (empty($data['evaluations']) || ! is_array($data['evaluations'])) {
            throw new AiServiceException("La réponse d'évaluation de Gemini est invalide (champ evaluations manquant).", 502);
        }

        $evalByReponseId = collect($data['evaluations'])->keyBy(fn ($item) => (int) ($item['reponse_id'] ?? 0));
        $validatedEvaluations = [];

        foreach ($questionsEtReponses as $expected) {
            $repId = (int) $expected['reponse_id'];
            $pointsMax = max(0.0, (float) $expected['points_max']);
            $rawEval = $evalByReponseId->get($repId);

            if (! is_array($rawEval) || ! isset($rawEval['points_obtenus']) || ! is_numeric($rawEval['points_obtenus'])) {
                throw new AiServiceException("Évaluation Gemini manquante ou invalide pour la réponse ID {$repId}.", 502);
            }

            // Plafonnement strict côté Laravel : ne jamais faire confiance aveuglément à la note brute de l'IA
            $pointsProposes = round((float) $rawEval['points_obtenus'], 2);
            $pointsValides = max(0.0, min($pointsProposes, $pointsMax));

            $estCorrecte = $pointsMax > 0 ? ($pointsValides >= ($pointsMax / 2.0)) : false;
            $commentaire = isset($rawEval['commentaire_correction']) && trim((string) $rawEval['commentaire_correction']) !== ''
                ? trim((string) $rawEval['commentaire_correction'])
                : 'Réponse évaluée selon le barème du chapitre.';

            $criteres = [];
            if (! empty($rawEval['criteres_valides']) && is_array($rawEval['criteres_valides'])) {
                foreach ($rawEval['criteres_valides'] as $c) {
                    if (is_string($c) && trim($c) !== '') {
                        $criteres[] = trim($c);
                    }
                }
            }

            $validatedEvaluations[$repId] = [
                'reponse_id' => $repId,
                'question_id' => (int) $expected['question_id'],
                'points_max' => $pointsMax,
                'points_obtenus' => $pointsValides,
                'est_correcte' => $estCorrecte,
                'criteres_valides' => $criteres,
                'commentaire_correction' => $commentaire,
            ];
        }

        return $validatedEvaluations;
    }

    /**
     * Valide strictement la structure d'un quiz ou d'un exercice généré par Gemini avant toute insertion en base.
     */
    private function validateGeneratedQuizOrExercice(
        array $data,
        string $defaultTitre,
        bool $allowOpenQuestions
    ): array {
        $titre = isset($data['titre']) && trim((string) $data['titre']) !== ''
            ? trim((string) $data['titre'])
            : "Évaluation IA : {$defaultTitre}";

        $description = isset($data['description']) && trim((string) $data['description']) !== ''
            ? trim((string) $data['description'])
            : "Questions générées par Gemini à partir du chapitre {$defaultTitre}.";

        if (empty($data['questions']) || ! is_array($data['questions'])) {
            throw new AiServiceException("Le contenu généré par Gemini ne contient aucune question valide.", 502);
        }

        $validatedQuestions = [];

        foreach ($data['questions'] as $idx => $q) {
            if (! is_array($q)) {
                continue;
            }

            $enonce = isset($q['enonce']) ? trim((string) $q['enonce']) : '';
            if ($enonce === '') {
                throw new AiServiceException("La question #" . ($idx + 1) . " générée par Gemini n'a pas d'énoncé.", 502);
            }

            $rawType = strtoupper(trim((string) ($q['type'] ?? 'QCM')));
            $type = ($allowOpenQuestions && $rawType === 'OUVERTE') ? 'OUVERTE' : 'QCM';

            $points = isset($q['points']) && is_numeric($q['points']) ? round((float) $q['points'], 2) : 2.0;
            $points = max(0.5, min($points, 10.0));

            $explication = isset($q['explication']) && trim((string) $q['explication']) !== ''
                ? trim((string) $q['explication'])
                : 'Voir les éléments correspondants dans le cours du chapitre.';

            $validatedPropositions = [];

            if ($type === 'QCM') {
                if (empty($q['propositions']) || ! is_array($q['propositions']) || count($q['propositions']) < 2) {
                    throw new AiServiceException(
                        "La question QCM #" . ($idx + 1) . " générée par Gemini doit comporter au moins 2 propositions.",
                        502
                    );
                }

                $correctCount = 0;
                foreach ($q['propositions'] as $p) {
                    if (! is_array($p)) {
                        continue;
                    }
                    $pContenu = isset($p['contenu']) ? trim((string) $p['contenu']) : '';
                    if ($pContenu === '') {
                        continue;
                    }
                    $estCorrecte = (bool) ($p['est_correcte'] ?? false);
                    if ($estCorrecte) {
                        $correctCount++;
                    }
                    $validatedPropositions[] = [
                        'contenu' => $pContenu,
                        'est_correcte' => $estCorrecte,
                    ];
                }

                if (count($validatedPropositions) < 2 || $correctCount < 1) {
                    throw new AiServiceException(
                        "La question QCM #" . ($idx + 1) . " générée par Gemini est incohérente (propositions insuffisantes ou aucune bonne réponse).",
                        502
                    );
                }
            }

            $validatedQuestions[] = [
                'enonce' => $enonce,
                'type' => $type,
                'points' => $points,
                'explication' => $explication,
                'propositions' => $validatedPropositions,
            ];
        }

        if (empty($validatedQuestions)) {
            throw new AiServiceException("Aucune question valide n'a pu être extraite de la réponse Gemini.", 502);
        }

        return [
            'titre' => $titre,
            'description' => $description,
            'questions' => $validatedQuestions,
        ];
    }

    /**
     * Effectue l'appel HTTP vers l'endpoint officiel Google Gemini `generateContent`
     * sans jamais exposer la clé API dans l'URL ni dans les messages d'erreur.
     */
    private function callGeminiJson(string $systemInstruction, string $userPrompt, float $temperature = 0.2): array
    {
        $apiKey = (string) config('services.gemini.key');
        $model = (string) config('services.gemini.model', 'gemini-flash-lite-latest');
        $baseUrl = rtrim((string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $timeout = max(5, (int) config('services.gemini.timeout', 30));

        if (trim($apiKey) === '') {
            throw new AiServiceException("La clé GEMINI_API_KEY n'est pas configurée sur le serveur.", 503);
        }

        $endpoint = "{$baseUrl}/models/{$model}:generateContent";

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $temperature,
                'responseMimeType' => 'application/json',
            ],
        ];

        $response = null;
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'x-goog-api-key' => $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                    ->timeout($timeout)
                    ->post($endpoint, $payload);
            } catch (ConnectionException $e) {
                if ($attempt === $maxAttempts) {
                    Log::error('Gemini API timeout ou erreur de connexion réseau.', [
                        'model' => $model,
                    ]);
                    throw new AiServiceException("Délai d'attente dépassé ou indisponibilité réseau lors de l'appel à Gemini AI.", 504);
                }
                sleep(1);
                continue;
            }

            // Retry automatique sur 503 (haute demande temporaire de l'API)
            if ($response->status() === 503 && $attempt < $maxAttempts) {
                Log::warning("Gemini API 503 haute demande temporaire, tentative {$attempt}/{$maxAttempts}...", ['model' => $model]);
                sleep(2);
                continue;
            }

            break;
        }

        if ($response->status() === 429) {
            Log::warning('Gemini API quota dépassé (HTTP 429).', ['model' => $model]);
            throw new AiServiceException("Le quota d'appels à l'API Gemini est temporairement atteint. Veuillez réessayer dans quelques instants.", 429);
        }

        if ($response->failed()) {
            $status = $response->status();
            $errorDetail = (string) data_get($response->json(), 'error.message', 'Erreur inconnue du fournisseur IA.');
            // Nettoyage préventif au cas où un message d'erreur mentionnerait une clé
            $safeDetail = str_replace($apiKey, '[REDACTED]', $errorDetail);

            Log::error('Erreur HTTP retournée par Gemini API.', [
                'status' => $status,
                'model' => $model,
                'detail' => $safeDetail,
            ]);

            throw new AiServiceException("Erreur lors du traitement par le service Gemini AI (HTTP {$status}).", 502);
        }

        $body = $response->json();
        $finishReason = (string) data_get($body, 'candidates.0.finishReason', '');

        if ($finishReason !== '' && ! in_array($finishReason, ['STOP', 'MAX_TOKENS'], true)) {
            Log::warning('Réponse Gemini interrompue par un filtre.', [
                'finishReason' => $finishReason,
                'model' => $model,
            ]);
            throw new AiServiceException("La génération IA a été interrompue ({$finishReason}).", 422);
        }

        $rawText = (string) data_get($body, 'candidates.0.content.parts.0.text', '');
        $cleanedText = $this->cleanJsonText($rawText);

        if ($cleanedText === '') {
            throw new AiServiceException("Le service Gemini AI a retourné une réponse vide.", 502);
        }

        $decoded = json_decode($cleanedText, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Réponse JSON invalide reçue de Gemini API.', [
                'json_error' => json_last_error_msg(),
                'model' => $model,
            ]);
            throw new AiServiceException("La réponse retournée par Gemini AI n'est pas un JSON valide.", 502);
        }

        return $decoded;
    }

    /**
     * Nettoie d'éventuelles balises Markdown ```json ... ``` autour du JSON.
     */
    private function cleanJsonText(string $text): string
    {
        $trimmed = trim($text);
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
        }
        return trim($trimmed);
    }
}
