<?php

namespace App\Models;

use App\VideoProcessing\Enums\VideoProcessingJobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoProcessingJob extends Model
{
    protected $fillable = [
        'user_id',
        'social_video_id',
        'type',
        'status',
        'params',
        'progress_percent',
        'progress_message',
        'worker_id',
        'claimed_at',
        'heartbeat_at',
        'attempts',
        'idempotency_key',
        'analysis_path',
        'error_message',
        'result_summary',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => VideoProcessingJobStatus::class,
            'params' => 'array',
            'result_summary' => 'array',
            'claimed_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(SocialVideo::class, 'social_video_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(VideoWorker::class, 'worker_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(VideoProcessingSegment::class, 'job_id')->orderBy('index');
    }

    public function isClaimedBy(VideoWorker $worker): bool
    {
        return $this->worker_id !== null
            && (int) $this->worker_id === (int) $worker->id;
    }
}
