<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Etudiant extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'etudiants';

    public $timestamps = false;

    protected $fillable = [
        'nom',
        'prenom',
        'email',
        'mot_de_passe_hash',
        'parcours_id',
        'date_inscription',
    ];

    protected $hidden = [
        'mot_de_passe_hash',
    ];

    protected function casts(): array
    {
        return [
            'date_inscription' => 'datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->mot_de_passe_hash;
    }

    public function isEtudiant(): bool
    {
        return true;
    }

    public function parcoursScolaire(): BelongsTo
    {
        return $this->belongsTo(ParcoursScolaire::class, 'parcours_id');
    }

    public function progressions(): HasMany
    {
        return $this->hasMany(Progression::class, 'etudiant_id');
    }

    public function consultationsCours(): HasMany
    {
        return $this->hasMany(ConsultationCours::class, 'etudiant_id');
    }

    public function tentatives(): HasMany
    {
        return $this->hasMany(Tentative::class, 'etudiant_id');
    }
}
