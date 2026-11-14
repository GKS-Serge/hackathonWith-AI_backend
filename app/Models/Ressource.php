<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ressource extends Model
{
    protected $table = 'ressources';

    public $timestamps = false;

    protected $fillable = [
        'chapitre_id',
        'titre',
        'type',
        'url',
    ];

    public function chapitre(): BelongsTo
    {
        return $this->belongsTo(Chapitre::class, 'chapitre_id');
    }
}
