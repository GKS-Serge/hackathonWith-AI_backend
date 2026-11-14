<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Matiere extends Model
{
    protected $table = 'matieres';

    protected $fillable = [
        'nom',
        'description',
    ];

    public function parcoursMatieres(): HasMany
    {
        return $this->hasMany(ParcoursMatiere::class, 'matiere_id');
    }

    public function parcoursScolaires(): BelongsToMany
    {
        return $this->belongsToMany(ParcoursScolaire::class, 'parcours_matieres', 'matiere_id', 'parcours_id')
            ->withPivot('id', 'ordre')
            ->withTimestamps();
    }
}
