<?php

namespace App\Contracts;

interface PedagogicalAiInterface
{
    /**
     * Génère un résumé pédagogique structuré à partir d'un cours ou d'une transcription vidéo.
     */
    public function generateResume(
        string $sourceType,
        string $titre,
        string $contenu,
        string $niveau,
        string $serie
    ): string;

    /**
     * Explique une notion ou un passage d'un cours en distinguant strictement le contenu source
     * et les compléments explicatifs adaptés au niveau et à la série de l'étudiant.
     */
    public function explainConcept(
        string $titreCours,
        string $contenuCours,
        string $questionOuNotion,
        string $niveau,
        string $serie
    ): array;

    /**
     * Génère un quiz QCM (évaluation ou révision) à partir des contenus sources d'un chapitre.
     */
    public function generateQuiz(
        string $titreChapitre,
        string $contenuSource,
        string $type,
        int $nbQuestions,
        string $niveau,
        string $serie
    ): array;

    /**
     * Génère un exercice progressif adapté au chapitre et au niveau/série de l'étudiant.
     */
    public function generateExercice(
        string $titreChapitre,
        string $contenuSource,
        int $nbQuestions,
        string $niveau,
        string $serie
    ): array;

    /**
     * Évalue des réponses textuelles libres d'un étudiant selon le barème et les critères pédagogiques.
     */
    public function evaluateOpenResponses(
        string $titreQuiz,
        string $contenuSource,
        array $questionsEtReponses,
        string $niveau,
        string $serie
    ): array;
}
