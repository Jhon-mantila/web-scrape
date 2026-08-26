<?php

namespace App\SocialPublishing\Actions;

use App\Models\SocialVideo;
use App\SocialPublishing\Enums\PublicationStatus;

class PublishAllSocialPublicationsAction
{
    public function __construct(
        private readonly PublishSocialPublicationAction $publish,
    ) {}

    /**
     * @return array{published: int, failed: int, skipped: int}
     */
    public function execute(SocialVideo $video): array
    {
        $video->load('publications');

        $published = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($video->publications as $publication) {
            if (config("social.platforms.{$publication->platform}.coming_soon")) {
                $skipped++;

                continue;
            }

            if ($publication->status === PublicationStatus::Published || $publication->status === PublicationStatus::Scheduled) {
                $skipped++;

                continue;
            }

            $result = $this->publish->execute($publication);

            if ($result->status === PublicationStatus::Published || $result->status === PublicationStatus::Scheduled) {
                $published++;
            } else {
                $failed++;
            }
        }

        return compact('published', 'failed', 'skipped');
    }
}
