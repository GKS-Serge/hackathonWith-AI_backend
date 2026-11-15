<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Video extends Model
{
    protected $table = 'videos';

    public $timestamps = true;

    protected $fillable = [
        'chapitre_id',
        'titre',
        'description',
        'youtube_id',
        'url',
        'duree',
        'duree_secondes',
        'thumbnail_url',
        'chaine_titre',
        'est_integrable',
        'statut_integration',
        'published_at',
    ];

    protected $casts = [
        'est_integrable' => 'boolean',
        'duree_secondes' => 'integer',
        'published_at' => 'datetime',
    ];

    /**
     * URL d'intégration iframe sécurisée conforme aux standards de confidentialité (youtube-nocookie).
     */
    public function getEmbedUrlAttribute(): ?string
    {
        if (empty($this->youtube_id)) {
            return null;
        }

        return "https://www.youtube-nocookie.com/embed/{$this->youtube_id}?rel=0&modestbranding=1";
    }

    public function chapitre(): BelongsTo
    {
        return $this->belongsTo(Chapitre::class, 'chapitre_id');
    }

    public function transcription(): HasOne
    {
        return $this->hasOne(Transcription::class, 'video_id');
    }
}
