<?php

namespace App\SocialPublishing\Support;

use App\Models\SocialPublication;
use App\Models\SocialVideo;
use App\SocialPublishing\Enums\PublicationStatus;

class SocialPublishRunPlanner
{
    /**
     * @param  list<int>|null  $publicationIds
     * @return list<array{key: string, label: string, status: string, detail: null, publication_id: int}>
     */
    public function stepsForVideo(SocialVideo $video, ?array $publicationIds): array
    {
        $video->loadMissing('publications');
        $steps = [];

        foreach ($video->publications as $publication) {
            if (! $this->shouldProcess($publication, $publicationIds)) {
                continue;
            }

            $steps[] = [
                'key' => 'pub_'.$publication->id,
                'label' => $this->platformLabel($publication),
                'status' => 'pending',
                'detail' => null,
                'publication_id' => $publication->id,
            ];
        }

        return $steps;
    }

    /**
     * @param  list<int>|null  $publicationIds
     */
    private function shouldProcess(SocialPublication $publication, ?array $publicationIds): bool
    {
        if ($publicationIds !== null && ! in_array($publication->id, $publicationIds, true)) {
            return false;
        }

        if (config("social.platforms.{$publication->platform}.coming_soon")) {
            return false;
        }

        return $publication->status !== PublicationStatus::Published
            && $publication->status !== PublicationStatus::Scheduled;
    }

    private function platformLabel(SocialPublication $publication): string
    {
        $label = config("social.platforms.{$publication->platform}.label");

        if (is_string($label) && $label !== '') {
            return $label;
        }

        return str_replace('_', ' ', ucfirst($publication->platform));
    }
}
