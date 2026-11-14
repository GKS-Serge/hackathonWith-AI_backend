<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParcoursScolaire extends Model
{
    protected $table = 'parcours_scolaires';

    protected $fillable = [
        'niveau_id',
        'serie_id',
    ];

    public function niveau(): BelongsTo
    {
        return $this->belongsTo(Niveau::class, 'niveau_id');
    }

    public function serie(): BelongsTo
    {
        return $this->belongsTo(Serie::class, 'serie_id');
    }

    public function etudiants(): HasMany
    {
        return $this->hasMany(Etudiant::class, 'parcours_id');
    }

    public function parcoursMatieres(): HasMany
    {
        return $this->hasMany(ParcoursMatiere::class, 'parcours_id');
    }

    public function matieres(): BelongsToMany
    {
        return $this->belongsToMany(Matiere::class, 'parcours_matieres', 'parcours_id', 'matiere_id')
            ->withPivot('id', 'ordre')
            ->withTimestamps();
    }
}
