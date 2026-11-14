<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cours extends Model
{
    protected $table = 'cours';

    public $timestamps = false;

    protected $fillable = [
        'chapitre_id',
        'titre',
        'type',
        'contenu',
        'date_creation',
    ];

    protected function casts(): array
    {
        return [
            'date_creation' => 'datetime',
        ];
    }

    public function chapitre(): BelongsTo
    {
        return $this->belongsTo(Chapitre::class, 'chapitre_id');
    }

    public function resumes(): HasMany
    {
        return $this->hasMany(Resume::class, 'cours_id');
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(ConsultationCours::class, 'cours_id');
    }
}
