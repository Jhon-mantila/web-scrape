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
        'content_text',
        'attempt_count',
        'last_error',
        'scraped_at',
        'processed_at',
    ];

    protected $casts = [
        'scraped_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }
}
