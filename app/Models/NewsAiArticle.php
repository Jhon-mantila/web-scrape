<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsAiArticle extends Model
{
    protected $fillable = [
        'news_id',
        'source_title',
        'generated_title',
        'excerpt',
        'body_html',
        'raw_ai_response',
        'sent_wordpress',
        'sent_wordpress_at',
        'model',
        'article_type',
    ];

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }

}
