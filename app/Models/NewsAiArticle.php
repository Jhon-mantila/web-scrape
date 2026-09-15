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
        'wordpress_post_id',
        'wordpress_status',
        'wordpress_scheduled_at',
        'wordpress_url',
        'wordpress_author',
        'model',
        'article_type',
    ];

    protected $casts = [
        'sent_wordpress' => 'boolean',
        'sent_wordpress_at' => 'datetime',
        'wordpress_scheduled_at' => 'datetime',
    ];

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }

}
