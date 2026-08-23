<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsDetail extends Model
{
    protected $fillable = [
        'news_id',
        'status',
        'raw_html',
        'featured_image_path',
        'featured_image_source',
        'research_context',
        'research_raw',
        'researched_at',
        'content_text',
        'attempt_count',
        'last_error',
        'scraped_at',
        'processed_at',
    ];

    protected $casts = [
        'scraped_at' => 'datetime',
        'processed_at' => 'datetime',
        'researched_at' => 'datetime',
        'research_raw' => 'array',
    ];

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }
}
