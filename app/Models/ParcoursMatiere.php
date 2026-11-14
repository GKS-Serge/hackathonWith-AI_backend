<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParcoursMatiere extends Model
{
    public const STATUT_A_VERIFIER = 'A_VERIFIER';
    public const STATUT_VALIDE = 'VALIDE';

    protected $table = 'parcours_matieres';

    protected $fillable = [
        'parcours_id',
        'matiere_id',
        'ordre',
        'source',
        'statut_validation',
    ];

    public function parcoursScolaire(): BelongsTo
    {
        return $this->belongsTo(ParcoursScolaire::class, 'parcours_id');
    }

    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class, 'matiere_id');
    }

    public function chapitres(): HasMany
    {
        return $this->hasMany(Chapitre::class, 'parcours_matiere_id');
    }
}
