<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tentative extends Model
{
    public const STATUT_EN_COURS = 'EN_COURS';
    public const STATUT_TERMINEE = 'TERMINEE';
    public const STATUT_EN_ATTENTE_CORRECTION = 'EN_ATTENTE_CORRECTION';

    protected $table = 'tentatives';

    public $timestamps = false;

    protected $fillable = [
        'etudiant_id',
        'quiz_id',
        'date_passage',
        'note',
        'statut',
    ];

    protected function casts(): array
    {
        return [
            'date_passage' => 'datetime',
            'note' => 'float',
        ];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class, 'etudiant_id');
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class, 'quiz_id');
    }

    public function reponses(): HasMany
    {
        return $this->hasMany(Reponse::class, 'tentative_id');
    }
}
