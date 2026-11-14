<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Niveau extends Model
{
    protected $table = 'niveaux';

    protected $fillable = [
        'nom',
        'ordre',
    ];

    public function parcoursScolaires(): HasMany
    {
        return $this->hasMany(ParcoursScolaire::class, 'niveau_id');
    }
}
