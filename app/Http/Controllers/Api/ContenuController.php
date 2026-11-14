<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapitre;
use App\Models\ConsultationCours;
use App\Models\Cours;
use App\Models\ParcoursMatiere;
use App\Models\Progression;
use App\Models\Ressource;
use App\Models\Resume;
use App\Models\Transcription;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContenuController extends Controller
{
    /**
     * Recherche et filtrage de cours par mot-clé (`q`), `type`, `chapitre_id`, `matiere_id` ou `mon_parcours`.
     */
    public function searchCours(Request $request): JsonResponse
    {
        $etudiant = $request->user();

        $query = Cours::with(['chapitre.parcoursMatiere.matiere', 'resumes']);

        if ($request->boolean('mon_parcours', false)) {
            $pmIds = ParcoursMatiere::where('parcours_id', $etudiant->parcours_id)->pluck('id');
            $chapitreIds = Chapitre::whereIn('parcours_matiere_id', $pmIds)->pluck('id');
            $query->whereIn('chapitre_id', $chapitreIds);
        }

        if ($request->filled('chapitre_id')) {
            $query->where('chapitre_id', (int) $request->query('chapitre_id'));
        }

        if ($request->filled('matiere_id')) {
            $matiereId = (int) $request->query('matiere_id');
            $query->whereHas('chapitre.parcoursMatiere', function ($q) use ($matiereId) {
                $q->where('matiere_id', $matiereId);
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('q')) {
            $search = trim((string) $request->query('q'));
            $query->where(function ($q) use ($search) {
                $q->where('titre', 'ILIKE', "%{$search}%")
                    ->orWhere('contenu', 'ILIKE', "%{$search}%");
            });
        }

        return response()->json([
            'cours' => $query->orderBy('id')->get(),
        ]);
    }

    /**
     * Liste les cours d'un chapitre avec leurs résumés.
     */
    public function coursByChapitre(Chapitre $chapitre): JsonResponse
    {
        $cours = Cours::with('resumes')
            ->where('chapitre_id', $chapitre->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'chapitre_id' => $chapitre->id,
            'cours' => $cours,
        ]);
    }

    /**
     * Affiche un cours spécifique avec ses résumés, enregistre la consultation
     * et met à jour automatiquement la progression du chapitre (plafonnée à 50% pour la seule lecture).
     */
    public function showCours(Request $request, Cours $cours): JsonResponse
    {
        $etudiant = $request->user();

        $cours->load(['chapitre.parcoursMatiere.matiere', 'resumes']);

        $consultation = $this->upsertConsultation($etudiant->id, $cours->id);
        $progression = Progression::recalculateForChapitre($etudiant->id, $cours->chapitre_id);

        return response()->json([
            'cours' => $cours,
            'consultation' => $consultation,
            'progression_chapitre' => $progression,
        ]);
    }

    /**
     * Enregistre ou met à jour explicitement la consultation d'un cours par l'étudiant connecté
     * et recalcule la progression du chapitre.
     */
    public function recordConsultation(Request $request, Cours $cours): JsonResponse
    {
        $etudiant = $request->user();

        $consultation = $this->upsertConsultation($etudiant->id, $cours->id);
        $progression = Progression::recalculateForChapitre($etudiant->id, $cours->chapitre_id);

        return response()->json([
            'message' => 'Consultation du cours enregistrée avec succès.',
            'consultation' => $consultation,
            'progression_chapitre' => $progression,
        ]);
    }

    /**
     * Historique des cours consultés par l'étudiant connecté, du plus récent au plus ancien.
     */
    public function myConsultations(Request $request): JsonResponse
    {
        $etudiant = $request->user();

        $consultations = ConsultationCours::with([
            'cours.chapitre.parcoursMatiere.matiere',
        ])
            ->where('etudiant_id', $etudiant->id)
            ->orderByDesc('date_consultation')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'consultations_cours' => $consultations,
        ]);
    }

    /**
     * Recherche et filtrage de vidéos YouTube par mot-clé (`q`), `chapitre_id`, `matiere_id` ou `mon_parcours`.
     */
    public function searchVideos(Request $request): JsonResponse
    {
        $etudiant = $request->user();

        $query = Video::with(['chapitre.parcoursMatiere.matiere', 'transcription']);

        if ($request->boolean('mon_parcours', false)) {
            $pmIds = ParcoursMatiere::where('parcours_id', $etudiant->parcours_id)->pluck('id');
            $chapitreIds = Chapitre::whereIn('parcours_matiere_id', $pmIds)->pluck('id');
            $query->whereIn('chapitre_id', $chapitreIds);
        }

        if ($request->filled('chapitre_id')) {
            $query->where('chapitre_id', (int) $request->query('chapitre_id'));
        }

        if ($request->filled('matiere_id')) {
            $matiereId = (int) $request->query('matiere_id');
            $query->whereHas('chapitre.parcoursMatiere', function ($q) use ($matiereId) {
                $q->where('matiere_id', $matiereId);
            });
        }

        if ($request->filled('q')) {
            $search = trim((string) $request->query('q'));
            $query->where(function ($q) use ($search) {
                $q->where('titre', 'ILIKE', "%{$search}%")
                    ->orWhere('youtube_id', 'ILIKE', "%{$search}%")
                    ->orWhereHas('transcription', function ($tq) use ($search) {
                        $tq->where('contenu', 'ILIKE', "%{$search}%");
                    });
            });
        }

        return response()->json([
            'videos' => $query->orderBy('id')->get(),
        ]);
    }

    /**
     * Liste les vidéos d'un chapitre avec leur transcription éventuelle.
     */
    public function videosByChapitre(Chapitre $chapitre): JsonResponse
    {
        $videos = Video::with('transcription')
            ->where('chapitre_id', $chapitre->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'chapitre_id' => $chapitre->id,
            'videos' => $videos,
        ]);
    }

    /**
     * Affiche le détail d'une vidéo avec sa transcription et les résumés de cette transcription.
     */
    public function showVideo(Video $video): JsonResponse
    {
        $video->load(['chapitre.parcoursMatiere.matiere', 'transcription.resumes']);

        return response()->json([
            'video' => $video,
        ]);
    }

    /**
     * Récupère la transcription d'une vidéo et ses résumés associés.
     */
    public function transcriptionByVideo(Video $video): JsonResponse
    {
        $transcription = Transcription::with('resumes')
            ->where('video_id', $video->id)
            ->first();

        if (! $transcription) {
            return response()->json([
                'message' => 'Aucune transcription disponible pour cette vidéo.',
            ], 404);
        }

        return response()->json([
            'video_id' => $video->id,
            'transcription' => $transcription,
        ]);
    }

    /**
     * Liste les ressources pédagogiques d'un chapitre (filtrables par type).
     */
    public function ressourcesByChapitre(Request $request, Chapitre $chapitre): JsonResponse
    {
        $query = Ressource::where('chapitre_id', $chapitre->id)->orderBy('id');

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        return response()->json([
            'chapitre_id' => $chapitre->id,
            'ressources' => $query->get(),
        ]);
    }

    /**
     * Affiche le détail d'une ressource pédagogique.
     */
    public function showRessource(Ressource $ressource): JsonResponse
    {
        $ressource->load('chapitre');

        return response()->json([
            'ressource' => $ressource,
        ]);
    }

    /**
     * Récupère les résumés associés à un cours donné.
     */
    public function resumesByCours(Cours $cours): JsonResponse
    {
        $resumes = Resume::where('cours_id', $cours->id)
            ->orderByDesc('date_creation')
            ->get();

        return response()->json([
            'cours_id' => $cours->id,
            'resumes' => $resumes,
        ]);
    }

    /**
     * Récupère les résumés associés à une transcription donnée.
     */
    public function resumesByTranscription(Transcription $transcription): JsonResponse
    {
        $resumes = Resume::where('transcription_id', $transcription->id)
            ->orderByDesc('date_creation')
            ->get();

        return response()->json([
            'transcription_id' => $transcription->id,
            'resumes' => $resumes,
        ]);
    }

    /**
     * Affiche le détail d'une transcription spécifique avec sa vidéo et ses résumés.
     */
    public function showTranscription(Transcription $transcription): JsonResponse
    {
        $transcription->load(['video.chapitre', 'resumes']);

        return response()->json([
            'transcription' => $transcription,
        ]);
    }

    /**
     * Affiche le détail d'un résumé spécifique avec sa source (cours ou transcription).
     */
    public function showResume(Resume $resume): JsonResponse
    {
        $resume->load(['cours.chapitre', 'transcription.video.chapitre']);

        return response()->json([
            'resume' => $resume,
        ]);
    }

    /**
     * Liste tous les résumés d'un chapitre (qu'ils proviennent des cours ou des transcriptions vidéo du chapitre).
     */
    public function resumesByChapitre(Chapitre $chapitre): JsonResponse
    {
        $coursIds = Cours::where('chapitre_id', $chapitre->id)->pluck('id');
        $videoIds = Video::where('chapitre_id', $chapitre->id)->pluck('id');
        $transcriptionIds = Transcription::whereIn('video_id', $videoIds)->pluck('id');

        $resumes = Resume::with(['cours:id,chapitre_id,titre', 'transcription:id,video_id'])
            ->where(function ($query) use ($coursIds, $transcriptionIds) {
                $query->whereIn('cours_id', $coursIds)
                    ->orWhereIn('transcription_id', $transcriptionIds);
            })
            ->orderByDesc('date_creation')
            ->get();

        return response()->json([
            'chapitre_id' => $chapitre->id,
            'resumes' => $resumes,
        ]);
    }

    /**
     * Crée ou met à jour la trace de consultation d'un cours pour un étudiant,
     * évitant ainsi les enregistrements redondants pour un même couple (etudiant_id, cours_id).
     */
    private function upsertConsultation(int $etudiantId, int $coursId): ConsultationCours
    {
        return ConsultationCours::updateOrCreate(
            [
                'etudiant_id' => $etudiantId,
                'cours_id' => $coursId,
            ],
            [
                'date_consultation' => now(),
            ]
        );
    }
}
