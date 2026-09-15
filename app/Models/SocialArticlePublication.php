<?php

namespace App\Models;

use App\SocialPublishing\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialArticlePublication extends Model
{
    protected $fillable = [
        'wordpress_post_id',
        'user_id',
        'platform',
        'status',
        'message',
        'scheduled_at',
        'published_at',
        'external_id',
        'external_url',
        'api_response',
        'last_error',
    ];

    protected $casts = [
        'status' => PublicationStatus::class,
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
        'api_response' => 'array',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(WordpressPost::class, 'wordpress_post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function platformLabel(): string
    {
        return config("social.platforms.{$this->platform}.label", $this->platform);
    }

    public function blocksRepublish(): bool
    {
        return in_array($this->status, [
            PublicationStatus::Published,
            PublicationStatus::Scheduled,
            PublicationStatus::Publishing,
        ], true);
    }

    public function facebookPostId(): ?string
    {
        if (is_string($this->external_id) && $this->external_id !== '') {
            return $this->external_id;
        }

        $fromResponse = $this->api_response['id'] ?? null;

        return is_string($fromResponse) && $fromResponse !== '' ? $fromResponse : null;
    }

    public function canDeleteFromFacebook(): bool
    {
        if (! str_starts_with($this->platform, 'facebook_')) {
            return false;
        }

        if ($this->facebookPostId() === null) {
            return false;
        }

        return in_array($this->status, [
            PublicationStatus::Published,
            PublicationStatus::Scheduled,
            PublicationStatus::Failed,
        ], true);
    }
}
