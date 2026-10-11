<?php

namespace App\SocialPublishing\Actions;

use App\Models\SocialArticlePublication;
use App\SocialPublishing\Enums\PublicationStatus;
use Illuminate\Support\Facades\Log;

class PublishDueQueuedArticlePublicationsAction
{
    public function __construct(
        private readonly PublishSocialArticleAction $publish,
    ) {}

    /**
     * @return array{processed: int, published: int, failed: int, skipped: int, message: string}
     */
    public function execute(): array
    {
        $processed = 0;
        $published = 0;
        $failed = 0;
        $skipped = 0;

        $due = SocialArticlePublication::query()
            ->with('post')
            ->whereNotNull('queued_publish_at')
            ->where('queued_publish_at', '<=', now())
            ->whereIn('status', [
                PublicationStatus::Queued,
                PublicationStatus::Failed,
            ])
            ->orderBy('queued_publish_at')
            ->limit(15)
            ->get();

        if ($due->isEmpty()) {
            return [
                'processed' => 0,
                'published' => 0,
                'failed' => 0,
                'skipped' => 0,
                'message' => 'No hay artículos en cola pendientes.',
            ];
        }

        foreach ($due as $publication) {
            $processed++;

            if (config("social.platforms.{$publication->platform}.coming_soon")) {
                $skipped++;

                continue;
            }

            $post = $publication->post;

            if ($post === null) {
                $skipped++;

                continue;
            }

            if ($publication->blocksRepublish() && $publication->status !== PublicationStatus::Failed) {
                $skipped++;
                $publication->update(['queued_publish_at' => null]);

                continue;
            }

            Log::info('social_article_queue: publicando', [
                'publication_id' => $publication->id,
                'post_id' => $post->id,
                'platform' => $publication->platform,
                'queued_publish_at' => $publication->queued_publish_at?->toIso8601String(),
            ]);

            try {
                $scheduledAt = str_starts_with($publication->platform, 'facebook_')
                    ? $publication->scheduled_at
                    : null;

                if ($scheduledAt !== null && $scheduledAt->isPast()) {
                    $scheduledAt = null;
                }

                $result = $this->publish->execute(
                    $post,
                    $publication->platform,
                    $publication->message,
                    (int) ($publication->user_id ?? 0),
                    $scheduledAt,
                );

                if (in_array($result->status, [PublicationStatus::Published, PublicationStatus::Scheduled], true)) {
                    $published++;
                    $publication->update(['queued_publish_at' => null]);
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $publication->update([
                    'status' => PublicationStatus::Failed,
                    'last_error' => $e->getMessage(),
                ]);
            }

            sleep(2);
        }

        $message = sprintf(
            '%d procesados · %d OK · %d fallidos · %d omitidos.',
            $processed,
            $published,
            $failed,
            $skipped,
        );

        return compact('processed', 'published', 'failed', 'skipped', 'message');
    }
}
