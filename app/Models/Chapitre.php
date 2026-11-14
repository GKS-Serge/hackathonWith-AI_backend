<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chapitre extends Model
{
    public const STATUT_A_VERIFIER = 'A_VERIFIER';
    public const STATUT_VALIDE = 'VALIDE';

    protected $table = 'chapitres';

    public $timestamps = false;

    protected $fillable = [
        'parcours_matiere_id',
        'titre',
        'description',
        'ordre',
        'source',
        'statut_validation',
        'est_demo',
    ];

    protected function casts(): array
    {
        return [
            'ordre' => 'integer',
            'est_demo' => 'boolean',
        ];
    }

    public function parcoursMatiere(): BelongsTo
    {
        return $this->belongsTo(ParcoursMatiere::class, 'parcours_matiere_id');
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class, 'chapitre_id');
    }

    public function ressources(): HasMany
    {
        return $this->hasMany(Ressource::class, 'chapitre_id');
    }

    public function cours(): HasMany
    {
        return $this->hasMany(Cours::class, 'chapitre_id');
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class, 'chapitre_id');
    }

    public function progressions(): HasMany
    {
        return $this->hasMany(Progression::class, 'chapitre_id');
    }
}
