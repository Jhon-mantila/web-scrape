<?php

namespace App\SocialPublishing\Actions;

use App\Models\SocialArticlePublication;
use App\Models\WordpressPost;
use App\SocialPublishing\Enums\PublicationStatus;
use Illuminate\Support\Carbon;

class PlanSocialArticlePublicationAction
{
    public function __construct(
        private readonly PublishSocialArticleAction $publish,
    ) {}

    public function execute(
        WordpressPost $post,
        string $platform,
        ?string $message,
        int $userId,
        ?Carbon $facebookScheduledAt,
        ?Carbon $queuedPublishAt,
        bool $publishNow,
    ): SocialArticlePublication {
        $site = $post->siteEnum();

        if ($site === null || ! $site->allowsPlatform($platform)) {
            throw new \InvalidArgumentException('Plataforma no permitida para este artículo.');
        }

        $post->loadMissing('publications');
        $existing = $post->publications->firstWhere('platform', $platform);

        if ($existing !== null && $existing->blocksRepublish()) {
            throw new \RuntimeException(
                "Este artículo ya fue enviado a {$existing->platformLabel()} ({$existing->status->label()}).",
            );
        }

        $text = $this->resolveMessage($post, $message);

        if ($publishNow) {
            $scheduledAt = str_starts_with($platform, 'facebook_') ? $facebookScheduledAt : null;

            return $this->publish->execute(
                $post,
                $platform,
                $text,
                $userId,
                $scheduledAt,
            );
        }

        if ($queuedPublishAt === null || ! $queuedPublishAt->isFuture()) {
            throw new \InvalidArgumentException('Indica una fecha futura para la cola de la app.');
        }

        $fbSchedule = str_starts_with($platform, 'facebook_') ? $facebookScheduledAt : null;

        $publication = SocialArticlePublication::query()->updateOrCreate(
            [
                'wordpress_post_id' => $post->id,
                'platform' => $platform,
            ],
            [
                'user_id' => $userId,
                'status' => PublicationStatus::Queued,
                'message' => $text,
                'scheduled_at' => $fbSchedule,
                'queued_publish_at' => $queuedPublishAt,
                'last_error' => null,
            ],
        );

        return $publication->fresh();
    }

    private function resolveMessage(WordpressPost $post, ?string $message): string
    {
        $text = trim((string) ($message ?? ''));

        if ($text !== '') {
            return $text;
        }

        if (is_string($post->excerpt) && trim($post->excerpt) !== '') {
            return trim($post->excerpt);
        }

        return $post->title;
    }
}
