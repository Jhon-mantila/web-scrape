<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class News extends Model
{
    protected $fillable = [
        'title',
        'url',
        'source',
        'image',
        'snippet',
        'category',
        'status_ia',
    ];

    public function detail(): HasOne
    {
        return $this->hasOne(NewsDetail::class);
    }

    public function aiArticle(): HasOne
    {
        return $this->hasOne(NewsAiArticle::class);
    }
}
