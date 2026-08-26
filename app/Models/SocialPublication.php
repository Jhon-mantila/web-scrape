<?php

namespace App\Models;

use App\SocialPublishing\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPublication extends Model
{
    protected $fillable = [
        'social_video_id',
        'platform',
        'status',
        'caption_generated',
        'caption_edited',
        'scheduled_at',
        'published_at',
        'external_id',
        'external_url',
        'api_response',
        'last_error',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'api_response' => 'array',
        'status' => PublicationStatus::class,
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(SocialVideo::class, 'social_video_id');
    }

    public function caption(): ?string
    {
        return $this->caption_edited ?: $this->caption_generated;
    }

    public function platformLabel(): string
    {
        return config("social.platforms.{$this->platform}.label", $this->platform);
    }
}
