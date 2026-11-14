<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\ParcoursMatiere;
use App\Models\ParcoursScolaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Inscription d'un nouvel étudiant.
     * Accepte soit `parcours_id`, soit le couple `(niveau_id, serie_id)` avec résolution automatique.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'prenom' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:etudiants,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'parcours_id' => ['required_without_all:niveau_id,serie_id', 'nullable', 'integer', 'exists:parcours_scolaires,id'],
            'niveau_id' => ['required_without:parcours_id', 'nullable', 'integer', 'exists:niveaux,id'],
            'serie_id' => ['required_without:parcours_id', 'nullable', 'integer', 'exists:series,id'],
        ]);

        $parcoursId = $this->resolveParcoursId($validated);

        $etudiant = Etudiant::create([
            'nom' => $validated['nom'],
            'prenom' => $validated['prenom'],
            'email' => $validated['email'],
            'mot_de_passe_hash' => Hash::make($validated['password']),
            'parcours_id' => $parcoursId,
            'date_inscription' => now(),
        ]);

        $etudiant->load(['parcoursScolaire.niveau', 'parcoursScolaire.serie']);

        $token = $etudiant->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Inscription réussie.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'etudiant' => $etudiant,
            'matieres_affectees' => $this->getAssignedMatieres($parcoursId),
        ], 201);
    }

    /**
     * Connexion d'un étudiant existant.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $etudiant = Etudiant::where('email', $validated['email'])->first();

        if (! $etudiant || ! Hash::check($validated['password'], $etudiant->mot_de_passe_hash)) {
            throw ValidationException::withMessages([
                'email' => ['Les identifiants fournis sont incorrects.'],
            ]);
        }

        $etudiant->load(['parcoursScolaire.niveau', 'parcoursScolaire.serie']);

        $token = $etudiant->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Connexion réussie.',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'etudiant' => $etudiant,
            'matieres_affectees' => $this->getAssignedMatieres((int) $etudiant->parcours_id),
        ]);
    }

    /**
     * Déconnexion de l'étudiant (révocation du token courant).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Déconnexion réussie.',
        ]);
    }

    /**
     * Récupération du profil de l'étudiant connecté et de ses matières affectées.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var Etudiant $etudiant */
        $etudiant = $request->user();
        $etudiant->load(['parcoursScolaire.niveau', 'parcoursScolaire.serie']);

        return response()->json([
            'etudiant' => $etudiant,
            'matieres_affectees' => $this->getAssignedMatieres((int) $etudiant->parcours_id),
        ]);
    }

    /**
     * Modification du profil de l'étudiant connecté (informations personnelles, niveau/série/parcours ou mot de passe).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        /** @var Etudiant $etudiant */
        $etudiant = $request->user();

        $validated = $request->validate([
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'prenom' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('etudiants', 'email')->ignore($etudiant->id),
            ],
            'parcours_id' => ['sometimes', 'nullable', 'integer', 'exists:parcours_scolaires,id'],
            'niveau_id' => ['required_with:serie_id', 'nullable', 'integer', 'exists:niveaux,id'],
            'serie_id' => ['required_with:niveau_id', 'nullable', 'integer', 'exists:series,id'],
            'current_password' => ['required_with:password', 'string'],
            'password' => ['sometimes', 'required', 'string', 'min:8', 'confirmed'],
        ]);

        if (isset($validated['password'])) {
            if (! Hash::check($validated['current_password'], $etudiant->mot_de_passe_hash)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Le mot de passe actuel est incorrect.'],
                ]);
            }
            $etudiant->mot_de_passe_hash = Hash::make($validated['password']);
        }

        if (! empty($validated['parcours_id']) || (! empty($validated['niveau_id']) && ! empty($validated['serie_id']))) {
            $etudiant->parcours_id = $this->resolveParcoursId($validated);
        }

        foreach (['nom', 'prenom', 'email'] as $field) {
            if (array_key_exists($field, $validated)) {
                $etudiant->{$field} = $validated[$field];
            }
        }

        $etudiant->save();
        $etudiant->load(['parcoursScolaire.niveau', 'parcoursScolaire.serie']);

        return response()->json([
            'message' => 'Profil mis à jour avec succès.',
            'etudiant' => $etudiant,
            'matieres_affectees' => $this->getAssignedMatieres((int) $etudiant->parcours_id),
        ]);
    }

    /**
     * Résout et vérifie la validité du parcours scolaire à partir de `parcours_id` ou du couple `(niveau_id, serie_id)`.
     */
    private function resolveParcoursId(array $validated): int
    {
        $hasNiveauSerie = ! empty($validated['niveau_id']) && ! empty($validated['serie_id']);
        $hasParcoursId = ! empty($validated['parcours_id']);

        if ($hasNiveauSerie) {
            $parcours = ParcoursScolaire::where('niveau_id', (int) $validated['niveau_id'])
                ->where('serie_id', (int) $validated['serie_id'])
                ->first();

            if (! $parcours) {
                throw ValidationException::withMessages([
                    'serie_id' => ["Aucun parcours scolaire valide n'existe pour la combinaison de ce niveau et de cette série."],
                ]);
            }

            if ($hasParcoursId && (int) $validated['parcours_id'] !== (int) $parcours->id) {
                throw ValidationException::withMessages([
                    'parcours_id' => ["Le parcours_id fourni ne correspond pas au couple (niveau_id, serie_id) sélectionné."],
                ]);
            }

            return (int) $parcours->id;
        }

        return (int) $validated['parcours_id'];
    }

    /**
     * Retourne la liste ordonnée des matières affectées à un parcours scolaire.
     */
    private function getAssignedMatieres(int $parcoursId): array
    {
        return ParcoursMatiere::with('matiere')
            ->where('parcours_id', $parcoursId)
            ->orderBy('ordre')
            ->get()
            ->map(function (ParcoursMatiere $pm) {
                return [
                    'parcours_matiere_id' => $pm->id,
                    'ordre' => $pm->ordre,
                    'matiere' => $pm->matiere,
                ];
            })
            ->values()
            ->all();
    }
}
