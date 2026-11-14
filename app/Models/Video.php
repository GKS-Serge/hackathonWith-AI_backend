<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Video extends Model
{
    protected $table = 'videos';

    public $timestamps = false;

    protected $fillable = [
        'chapitre_id',
        'titre',
        'youtube_id',
        'url',
    ];

    public function chapitre(): BelongsTo
    {
        return $this->belongsTo(Chapitre::class, 'chapitre_id');
    }

    public function transcription(): HasOne
    {
        return $this->hasOne(Transcription::class, 'video_id');
    }
}
