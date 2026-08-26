<?php

namespace App\SocialPublishing\Contracts;

use App\Models\SocialPublication;
use App\SocialPublishing\DTO\PublishResult;

interface SocialPublisherInterface
{
    public function platform(): string;

    public function isConfigured(): bool;

    public function publish(SocialPublication $publication): PublishResult;
}
