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
     * @param  list<int>|null  $publicationIds  Si se indica, solo se procesan esas publicaciones.
     * @return array{published: int, failed: int, skipped: int}
     */
    public function execute(SocialVideo $video, ?array $publicationIds = null): array
    {
        $video->load('publications');

        $published = 0;
        $failed = 0;
        $skipped = 0;
        $processed = 0;

        foreach ($video->publications as $publication) {
            if ($publicationIds !== null && ! in_array($publication->id, $publicationIds, true)) {
                continue;
            }

            if (config("social.platforms.{$publication->platform}.coming_soon")) {
                $skipped++;

                continue;
            }

            if ($publication->status === PublicationStatus::Published || $publication->status === PublicationStatus::Scheduled) {
                $skipped++;

                continue;
            }

            if ($processed > 0) {
                // Tras subidas pesadas (YouTube/Facebook), el DNS del contenedor puede fallar momentáneamente.
                sleep(3);
            }

            $result = $this->publish->execute($publication);

            if ($result->status === PublicationStatus::Published || $result->status === PublicationStatus::Scheduled) {
                $published++;
            } else {
                $failed++;
            }

            $processed++;
        }

        return compact('published', 'failed', 'skipped');
    }
}
