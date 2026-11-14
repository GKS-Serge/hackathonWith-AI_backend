<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Serie extends Model
{
    protected $table = 'series';

    protected $fillable = [
        'code',
        'libelle',
    ];

    public function parcoursScolaires(): HasMany
    {
        return $this->hasMany(ParcoursScolaire::class, 'serie_id');
    }
}
