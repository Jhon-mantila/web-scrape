<?php

namespace App\Models;

use App\SocialPublishing\Enums\VideoCaptionAiProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialVideo extends Model
{
    protected $fillable = [
        'user_id',
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

    public function publications(): HasMany
    {
        return $this->hasMany(SocialPublication::class);
    }
}
