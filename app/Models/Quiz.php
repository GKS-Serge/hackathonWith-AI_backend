<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    public const TYPE_QUIZ = 'QUIZ';
    public const TYPE_EXERCICE = 'EXERCICE';
    public const TYPE_REVISION = 'REVISION';

    public const ORIGINE_MANUEL = 'MANUEL';
    public const ORIGINE_MISTRAL = 'MISTRAL';
    public const ORIGINE_GEMINI = 'GEMINI';

    protected $table = 'quizzes';

    public $timestamps = false;

    protected $fillable = [
        'chapitre_id',
        'titre',
        'description',
        'type',
        'origine',
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

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'quiz_id');
    }

    public function tentatives(): HasMany
    {
        return $this->hasMany(Tentative::class, 'quiz_id');
    }
}
