<?php

namespace App\Models;

use App\SocialPublishing\Enums\VideoCaptionAiProvider;
use App\VideoProcessing\Enums\VideoKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialVideo extends Model
{
    protected $fillable = [
        'user_id',
        'parent_video_id',
        'kind',
        'source_job_id',
        'clip_index',
        'starts_at_ms',
        'ends_at_ms',
        'title',
        'video_path',
        'thumbnail_path',
        'notes',
        'caption_ai_provider',
        'caption_ollama_target',
        'caption_ai_model',
    ];

    public function captionAiProvider(): VideoCaptionAiProvider
    {
        return VideoCaptionAiProvider::tryFromStored($this->caption_ai_provider);
    }

    public function usesCaptionAi(): bool
    {
        return $this->captionAiProvider() !== VideoCaptionAiProvider::None;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_video_id');
    }

    public function clips(): HasMany
    {
        return $this->hasMany(self::class, 'parent_video_id')->orderBy('clip_index');
    }

    public function sourceJob(): BelongsTo
    {
        return $this->belongsTo(VideoProcessingJob::class, 'source_job_id');
    }

    public function processingJobs(): HasMany
    {
        return $this->hasMany(VideoProcessingJob::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(SocialPublication::class);
    }

    public function isClip(): bool
    {
        return $this->kind === VideoKind::Clip->value
            || $this->parent_video_id !== null;
    }

    public function isOriginal(): bool
    {
        return ! $this->isClip();
    }
}
