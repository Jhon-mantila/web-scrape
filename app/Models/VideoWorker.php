<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoWorker extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'token_hash',
        'capabilities',
        'last_seen_at',
        'current_job_id',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'last_seen_at' => 'datetime',
            'is_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(VideoProcessingJob::class, 'worker_id');
    }

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
