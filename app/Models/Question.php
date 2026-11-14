<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    public const TYPE_QCM = 'QCM';
    public const TYPE_OUVERTE = 'OUVERTE';

    protected $table = 'questions';

    public $timestamps = false;

    protected $fillable = [
        'quiz_id',
        'enonce',
        'type',
        'points',
        'explication',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'float',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class, 'quiz_id');
    }

    public function propositions(): HasMany
    {
        return $this->hasMany(Proposition::class, 'question_id');
    }

    public function reponses(): HasMany
    {
        return $this->hasMany(Reponse::class, 'question_id');
    }
}
