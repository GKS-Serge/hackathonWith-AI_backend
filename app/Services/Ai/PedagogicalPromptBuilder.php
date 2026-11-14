<?php

namespace App\Services\Ai;

class PedagogicalPromptBuilder
{
    /**
     * Instruction système commune garantissant la rigueur pédagogique,
     * le respect du niveau/série et l'ancrage strict sur les contenus de la plateforme.
     */
    public function buildSystemInstruction(string $niveau, string $serie): string
    {
        return <<<EOT
Tu es l'assistant pédagogique officiel de la plateforme académique SCHOLARSHIP / ACADÉMIE.
Tu t'adresses à un élève de niveau "{$niveau}" en série "{$serie}".

Règles strictes et obligatoires :
1. Utilise EXCLUSIVEMENT le contenu pédagogique source fourni dans le message utilisateur comme base factuelle.
2. N'invente jamais de faits, théorèmes, dates ou formules qui contredisent ou sortent du cadre du programme de "{$niveau}" (série "{$serie}").
3. Adapte ton vocabulaire, ta rigueur et tes exemples au niveau "{$niveau}" ({$serie}).
4. Réponds UNIQUEMENT avec un objet JSON valide respectant exactement le schéma demandé, sans balises Markdown autour du JSON.
EOT;
    }

    /**
     * Prompt pour la génération d'un résumé de cours ou de transcription vidéo.
     */
    public function buildResumePrompt(
        string $sourceType,
        string $titre,
        string $contenu
    ): string {
        return <<<EOT
Génère un résumé pédagogique clair, structuré et fidèle à partir de la source suivante ({$sourceType}).

Titre de la source : {$titre}
Contenu source :
"""
{$contenu}
"""

Réponds uniquement avec un objet JSON respectant ce format exact :
{
  "resume": "Texte complet du résumé structuré (points essentiels, définitions/formules clés et conclusion à retenir).",
  "points_cles": ["Point clé 1", "Point clé 2"]
}
EOT;
    }

    /**
     * Prompt pour l'explication d'une notion ou d'un passage d'un cours.
     */
    public function buildExplicationPrompt(
        string $titreCours,
        string $contenuCours,
        string $questionOuNotion
    ): string {
        return <<<EOT
L'étudiant demande une explication pédagogique sur la notion ou question suivante : "{$questionOuNotion}".

Titre du cours : {$titreCours}
Contenu officiel du cours :
"""
{$contenuCours}
"""

Consignes strictes :
- Distingue clairement ce qui figure explicitement dans le cours (`elements_issus_du_cours`) et tes explications pédagogiques complémentaires (`explication_pedagogique`).
- Si la question porte sur un élément non mentionné dans le cours, signale-le honnêtement dans `elements_issus_du_cours`.
- Propose au moins un exemple concret adapté au niveau de l'élève (`exemples_pedagogiques`).

Réponds uniquement avec un objet JSON respectant ce format exact :
{
  "notion_demandee": "{$questionOuNotion}",
  "elements_issus_du_cours": "Citations ou reformulation stricte des éléments présents dans le texte du cours.",
  "explication_pedagogique": "Explication détaillée, progressive et adaptée au niveau et à la série de l'élève.",
  "exemples_pedagogiques": ["Exemple concret 1", "Exemple concret 2"],
  "a_retenir": "Synthèse courte en 1 ou 2 phrases."
}
EOT;
    }

    /**
     * Prompt pour la génération d'un Quiz QCM (QUIZ ou REVISION).
     */
    public function buildQuizPrompt(
        string $titreChapitre,
        string $contenuSource,
        string $type,
        int $nbQuestions
    ): string {
        return <<<EOT
Génère un questionnaire à choix multiples (type : {$type}) comportant exactement {$nbQuestions} questions QCM basées exclusivement sur le contenu du chapitre suivant.

Chapitre : {$titreChapitre}
Contenu pédagogique source :
"""
{$contenuSource}
"""

Consignes strictes :
- Chaque question doit être de type "QCM", claire et sans ambiguïté.
- Chaque question doit comporter entre 3 et 4 propositions.
- Pour chaque question, EXACTEMENT UNE proposition doit avoir `"est_correcte": true` et les autres `"est_correcte": false`.
- Fournis une explication pédagogique détaillée (`explication`) justifiant la bonne réponse à partir du cours.

Réponds uniquement avec un objet JSON respectant ce format exact :
{
  "titre": "Titre du quiz",
  "description": "Description pédagogique courte du quiz",
  "questions": [
    {
      "enonce": "Énoncé de la question",
      "type": "QCM",
      "points": 2.0,
      "explication": "Explication détaillée de la bonne réponse.",
      "propositions": [
        {"contenu": "Réponse A", "est_correcte": true},
        {"contenu": "Réponse B", "est_correcte": false},
        {"contenu": "Réponse C", "est_correcte": false}
      ]
    }
  ]
}
EOT;
    }

    /**
     * Prompt pour la génération d'un Exercice progressif.
     */
    public function buildExercicePrompt(
        string $titreChapitre,
        string $contenuSource,
        int $nbQuestions
    ): string {
        return <<<EOT
Génère un exercice d'application progressif comportant exactement {$nbQuestions} questions (de difficulté croissante : compréhension directe, application, puis raisonnement) basées exclusivement sur le chapitre suivant.

Chapitre : {$titreChapitre}
Contenu pédagogique source :
"""
{$contenuSource}
"""

Consignes strictes :
- Les premières questions doivent être de type "QCM" (avec 3 ou 4 propositions dont exactement une `"est_correcte": true`).
- La dernière question peut être soit de type "QCM", soit de type "OUVERTE" (question de rédaction/raisonnement où `"propositions": []`).
- Chaque question doit avoir une consigne claire (`enonce`), un barème (`points`), et un corrigé méthodologique complet dans `explication`.

Réponds uniquement avec un objet JSON respectant ce format exact :
{
  "titre": "Exercice progressif : ...",
  "description": "Énoncé introductif et objectifs de l'exercice",
  "questions": [
    {
      "enonce": "Question 1 (Niveau facile - vérification des acquis) : ...",
      "type": "QCM",
      "points": 2.0,
      "explication": "Corrigé détaillé et méthode.",
      "propositions": [
        {"contenu": "Proposition 1", "est_correcte": true},
        {"contenu": "Proposition 2", "est_correcte": false},
        {"contenu": "Proposition 3", "est_correcte": false}
      ]
    }
  ]
}
EOT;
    }

    /**
     * Prompt pour l'évaluation assistée des réponses textuelles libres selon un barème strict.
     */
    public function buildEvaluationPrompt(
        string $titreQuiz,
        string $contenuSource,
        array $questionsEtReponses
    ): string {
        $itemsJson = json_encode($questionsEtReponses, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<EOT
Évalue de manière juste, rigoureuse et bienveillante les réponses textuelles suivantes fournies par un étudiant dans le cadre de l'évaluation "{$titreQuiz}".

Contenu pédagogique de référence du chapitre :
"""
{$contenuSource}
"""

Questions ouvertes, barèmes, éléments de corrigé attendus et réponses de l'étudiant :
{$itemsJson}

Consignes strictes d'évaluation :
- Pour chaque `reponse_id`, compare la `reponse_etudiant` aux `elements_attendus` et au contenu du cours.
- Attribue `points_obtenus` compris entre `0` et `points_max` (ne dépasse jamais `points_max`).
- Définis `est_correcte` à `true` si l'étudiant obtient au moins 50% des points de la question (`points_obtenus >= points_max / 2`), sinon `false`.
- Fournis dans `commentaire_correction` une explication détaillée indiquant les critères réussis et ce qu'il fallait préciser pour obtenir la note maximale.

Réponds uniquement avec un objet JSON respectant ce format exact :
{
  "evaluations": [
    {
      "reponse_id": 1,
      "question_id": 1,
      "points_obtenus": 1.5,
      "est_correcte": true,
      "criteres_valides": ["Critère 1"],
      "commentaire_correction": "Explication détaillée et conseils pédagogiques."
    }
  ]
}
EOT;
    }
}
