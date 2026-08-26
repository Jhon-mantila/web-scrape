<?php

namespace App\SocialPublishing\Actions;

use App\Models\SocialPublication;
use App\SocialPublishing\Enums\PublicationStatus;
use App\SocialPublishing\Registry\SocialPublisherRegistry;

class PublishSocialPublicationAction
{
    public function __construct(
        private readonly SocialPublisherRegistry $registry,
    ) {}

    public function execute(SocialPublication $publication): SocialPublication
    {
        $publication->load('video');
        $platform = $publication->platform;

        if (config("social.platforms.{$platform}.coming_soon")) {
            $publication->update([
                'status' => PublicationStatus::Unavailable,
                'last_error' => 'Plataforma próximamente.',
            ]);

            return $publication->fresh();
        }

        if (in_array($publication->status, [PublicationStatus::Published, PublicationStatus::Scheduled], true)) {
            return $publication;
        }

        $publication->update([
            'status' => PublicationStatus::Publishing,
            'last_error' => null,
        ]);

        try {
            $publisher = $this->registry->get($platform);
            $result = $publisher->publish($publication);

            if ($result->success) {
                $isScheduled = $publication->scheduled_at?->isFuture() ?? false;

                $publication->update([
                    'status' => $isScheduled ? PublicationStatus::Scheduled : PublicationStatus::Published,
                    'published_at' => now(),
                    'external_id' => $result->externalId,
                    'external_url' => $result->externalUrl,
                    'api_response' => $result->rawResponse,
                    'last_error' => null,
                ]);
            } else {
                $publication->update([
                    'status' => PublicationStatus::Failed,
                    'api_response' => $result->rawResponse,
                    'last_error' => $result->error,
                ]);
            }
        } catch (\Throwable $e) {
            $publication->update([
                'status' => PublicationStatus::Failed,
                'last_error' => $e->getMessage(),
            ]);
        }

        return $publication->fresh();
    }
}
