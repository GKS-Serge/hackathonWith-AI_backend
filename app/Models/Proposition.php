<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proposition extends Model
{
    protected $table = 'propositions';

    public $timestamps = false;

    protected $fillable = [
        'question_id',
        'contenu',
        'est_correcte',
    ];

    protected function casts(): array
    {
        return [
            'est_correcte' => 'boolean',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    public function reponses(): HasMany
    {
        return $this->hasMany(Reponse::class, 'proposition_id');
    }
}
