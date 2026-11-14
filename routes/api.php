<?php

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContenuController;
use App\Http\Controllers\Api\ProgressionController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\ReferentielController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::match(['put', 'patch'], '/me', [AuthController::class, 'updateProfile']);
        Route::match(['put', 'patch'], '/profile', [AuthController::class, 'updateProfile']);
    });
});

// Routes publiques du référentiel nécessaires à l'écran d'inscription (sélection du niveau, de la série et du parcours)
// et accessibles également par les étudiants connectés
Route::get('/niveaux', [ReferentielController::class, 'niveaux']);
Route::get('/niveaux/{niveau}/series', [ReferentielController::class, 'seriesByNiveau']);
Route::get('/series', [ReferentielController::class, 'series']);
Route::get('/parcours', [ReferentielController::class, 'parcours']);

Route::middleware('auth:sanctum')->group(function () {
    // 1. Référentiel pédagogique & Tableau de bord personnalisé
    Route::get('/dashboard', [ProgressionController::class, 'dashboard']);
    Route::get('/referentiel/document-source', [ReferentielController::class, 'downloadDocumentSource']);
    Route::get('/mes-matieres', [ReferentielController::class, 'mesMatieres']);
    Route::get('/matieres', [ReferentielController::class, 'matieres']);
    Route::get('/parcours/{parcours}/matieres', [ReferentielController::class, 'matieresByParcours']);
    Route::get('/matieres/{matiere}/chapitres', [ReferentielController::class, 'chapitresByMatiere']);
    Route::get('/parcours-matieres/{parcoursMatiere}/chapitres', [ReferentielController::class, 'chapitresByParcoursMatiere']);
    Route::get('/chapitres/{chapitre}', [ReferentielController::class, 'showChapitre']);

    // 2. Consultation & Recherche des contenus pédagogiques + Traçabilité
    Route::get('/cours', [ContenuController::class, 'searchCours']);
    Route::get('/chapitres/{chapitre}/cours', [ContenuController::class, 'coursByChapitre']);
    Route::get('/chapitres/{chapitre}/resumes', [ContenuController::class, 'resumesByChapitre']);
    Route::get('/cours/{cours}', [ContenuController::class, 'showCours']);
    Route::post('/cours/{cours}/consulter', [ContenuController::class, 'recordConsultation']);
    Route::get('/cours/{cours}/resumes', [ContenuController::class, 'resumesByCours']);
    Route::get('/consultations-cours', [ContenuController::class, 'myConsultations']);

    Route::get('/videos', [ContenuController::class, 'searchVideos']);
    Route::get('/chapitres/{chapitre}/videos', [ContenuController::class, 'videosByChapitre']);
    Route::get('/videos/{video}', [ContenuController::class, 'showVideo']);
    Route::get('/videos/{video}/transcription', [ContenuController::class, 'transcriptionByVideo']);
    Route::get('/transcriptions/{transcription}', [ContenuController::class, 'showTranscription']);
    Route::get('/transcriptions/{transcription}/resumes', [ContenuController::class, 'resumesByTranscription']);
    Route::get('/resumes/{resume}', [ContenuController::class, 'showResume']);

    Route::get('/chapitres/{chapitre}/ressources', [ContenuController::class, 'ressourcesByChapitre']);
    Route::get('/ressources/{ressource}', [ContenuController::class, 'showRessource']);

    // 3. Système de Quiz, Exercices et Tentatives
    Route::get('/quizzes', [QuizController::class, 'index']);
    Route::get('/chapitres/{chapitre}/quizzes', [QuizController::class, 'indexByChapitre']);
    Route::get('/quizzes/{quiz}', [QuizController::class, 'show']);
    Route::post('/quizzes/{quiz}/tentatives', [QuizController::class, 'startTentative']);
    Route::get('/tentatives', [QuizController::class, 'myTentatives']);
    Route::get('/tentatives/{tentative}', [QuizController::class, 'showTentative']);
    Route::post('/tentatives/{tentative}/soumettre', [QuizController::class, 'submitTentative']);

    // 4. Suivi de Progression
    Route::get('/progressions', [ProgressionController::class, 'index']);
    Route::get('/chapitres/{chapitre}/progression', [ProgressionController::class, 'showByChapitre']);
    Route::put('/chapitres/{chapitre}/progression', [ProgressionController::class, 'upsert']);

    // 5. Fonctionnalités Pédagogiques Intelligentes (Google Gemini AI) - Limitées à 10 req/min
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/cours/{cours}/generer-resume', [AiController::class, 'generateCoursResume']);
        Route::post('/transcriptions/{transcription}/generer-resume', [AiController::class, 'generateTranscriptionResume']);
        Route::post('/cours/{cours}/expliquer', [AiController::class, 'explainCours']);
        Route::post('/chapitres/{chapitre}/generer-quiz', [AiController::class, 'generateQuiz']);
        Route::post('/chapitres/{chapitre}/generer-exercice', [AiController::class, 'generateExercice']);
        Route::post('/tentatives/{tentative}/evaluer-reponses', [AiController::class, 'evaluateTentativeOpenResponses']);
    });
});

