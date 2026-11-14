<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationCours extends Model
{
    protected $table = 'consultation_cours';

    public $timestamps = false;

    protected $fillable = [
        'etudiant_id',
        'cours_id',
        'date_consultation',
    ];

    protected function casts(): array
    {
        return [
            'date_consultation' => 'datetime',
        ];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class, 'etudiant_id');
    }

    public function cours(): BelongsTo
    {
        return $this->belongsTo(Cours::class, 'cours_id');
    }
}
