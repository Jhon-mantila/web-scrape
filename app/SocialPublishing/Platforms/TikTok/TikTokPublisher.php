<?php

namespace App\SocialPublishing\Platforms\TikTok;

use App\Models\SocialPublication;
use App\SocialPublishing\Contracts\SocialPublisherInterface;
use App\SocialPublishing\DTO\PublishResult;

class TikTokPublisher implements SocialPublisherInterface
{
    public function platform(): string
    {
        return 'tiktok';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function publish(SocialPublication $publication): PublishResult
    {
        return PublishResult::fail('TikTok estará disponible en una próxima versión.');
    }
}
