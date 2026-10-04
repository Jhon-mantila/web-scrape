<?php

namespace App\SocialPublishing\Actions;

use App\Models\SocialPublication;
use App\SocialPublishing\Enums\PublicationStatus;
use Illuminate\Support\Facades\Log;

class PublishDueQueuedSocialPublicationsAction
{
    public function __construct(
        private readonly PublishSocialPublicationAction $publish,
    ) {}

    /**
     * @return array{processed: int, published: int, failed: int, skipped: int}
     */
    public function execute(): array
    {
        $processed = 0;
        $published = 0;
        $failed = 0;
        $skipped = 0;

        $due = SocialPublication::query()
            ->with('video')
            ->whereNotNull('queued_publish_at')
            ->where('queued_publish_at', '<=', now())
            ->whereIn('status', [
                PublicationStatus::Draft,
                PublicationStatus::CaptionReady,
                PublicationStatus::Queued,
                PublicationStatus::Failed,
            ])
            ->orderBy('queued_publish_at')
            ->limit(20)
            ->get();

        foreach ($due as $publication) {
            $processed++;

            if (config("social.platforms.{$publication->platform}.coming_soon")) {
                $skipped++;

                continue;
            }

            if ($publication->video === null) {
                $skipped++;

                continue;
            }

            Log::info('social_queue: publicando', [
                'publication_id' => $publication->id,
                'platform' => $publication->platform,
                'queued_publish_at' => $publication->queued_publish_at?->toIso8601String(),
            ]);

            $result = $this->publish->execute($publication);

            if (in_array($result->status, [PublicationStatus::Published, PublicationStatus::Scheduled], true)) {
                $published++;
                $publication->update(['queued_publish_at' => null]);
            } else {
                $failed++;
            }

            sleep(2);
        }

        return compact('processed', 'published', 'failed', 'skipped');
    }
}
