<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoProcessingSegment extends Model
{
    protected $fillable = [
        'job_id',
        'index',
        'start_ms',
        'end_ms',
        'source',
        'score',
        'warnings',
        'approved',
        'output_video_id',
    ];

    protected function casts(): array
    {
        return [
            'warnings' => 'array',
            'approved' => 'boolean',
            'score' => 'float',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(VideoProcessingJob::class, 'job_id');
    }

    public function outputVideo(): BelongsTo
    {
        return $this->belongsTo(SocialVideo::class, 'output_video_id');
    }
}
