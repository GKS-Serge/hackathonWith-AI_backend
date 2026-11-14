<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reponse extends Model
{
    protected $table = 'reponses';

    public $timestamps = false;

    protected $fillable = [
        'tentative_id',
        'question_id',
        'proposition_id',
        'contenu',
        'est_correcte',
        'points_obtenus',
    ];

    protected function casts(): array
    {
        return [
            'est_correcte' => 'boolean',
            'points_obtenus' => 'float',
        ];
    }

    public function tentative(): BelongsTo
    {
        return $this->belongsTo(Tentative::class, 'tentative_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    public function proposition(): BelongsTo
    {
        return $this->belongsTo(Proposition::class, 'proposition_id');
    }
}
