<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transcription extends Model
{
    protected $table = 'transcriptions';

    public $timestamps = false;

    protected $fillable = [
        'video_id',
        'contenu',
        'date_creation',
    ];

    protected function casts(): array
    {
        return [
            'date_creation' => 'datetime',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class, 'video_id');
    }

    public function resumes(): HasMany
    {
        return $this->hasMany(Resume::class, 'transcription_id');
    }
}
