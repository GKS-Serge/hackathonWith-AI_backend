<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resume extends Model
{
    protected $table = 'resumes';

    public $timestamps = false;

    protected $fillable = [
        'cours_id',
        'transcription_id',
        'contenu',
        'date_creation',
    ];

    protected function casts(): array
    {
        return [
            'date_creation' => 'datetime',
        ];
    }

    public function cours(): BelongsTo
    {
        return $this->belongsTo(Cours::class, 'cours_id');
    }

    public function transcription(): BelongsTo
    {
        return $this->belongsTo(Transcription::class, 'transcription_id');
    }
}
