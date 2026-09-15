<?php

namespace App\SocialPublishing\Actions;

use App\Models\SocialArticlePublication;
use App\Models\WordpressPost;
use App\SocialPublishing\Enums\PublicationStatus;
use App\SocialPublishing\DTO\PublishResult;
use App\SocialPublishing\Platforms\Facebook\FacebookLinkPublisher;
use App\SocialPublishing\Platforms\LinkedIn\LinkedInLinkPublisher;
use App\SocialPublishing\Platforms\LinkedIn\LinkedInTokenService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class PublishSocialArticleAction
{
    public function __construct(
        private readonly FacebookLinkPublisher $facebookLinks,
    ) {}

    public function execute(
        WordpressPost $post,
        string $platform,
        ?string $message,
        int $userId,
        ?Carbon $scheduledAt = null,
    ): SocialArticlePublication {
        $post->loadMissing('publications');

        $site = $post->siteEnum();

        if ($site === null || ! $site->allowsPlatform($platform)) {
            throw new \InvalidArgumentException('Esta plataforma no corresponde al sitio WordPress del artículo.');
        }

        $existing = $post->publications->firstWhere('platform', $platform);

        if ($existing !== null && $existing->blocksRepublish()) {
            throw new \RuntimeException(
                "Este artículo ya fue enviado a {$existing->platformLabel()} ({$existing->status->label()}).",
            );
        }

        $isScheduled = str_starts_with($platform, 'facebook_')
            && $scheduledAt !== null
            && $scheduledAt->isFuture();

        if ($isScheduled === false) {
            $scheduledAt = null;
        }

        $publication = SocialArticlePublication::query()->updateOrCreate(
            [
                'wordpress_post_id' => $post->id,
                'platform' => $platform,
            ],
            [
                'user_id' => $userId,
                'status' => PublicationStatus::Publishing,
                'message' => $message,
                'scheduled_at' => $scheduledAt,
                'last_error' => null,
            ],
        );

        $text = $this->buildMessage($post, $message);
        $result = $this->dispatchPublish($platform, $post, $text, $scheduledAt);

        if ($result->success) {
            $publication->update([
                'status' => $isScheduled ? PublicationStatus::Scheduled : PublicationStatus::Published,
                'published_at' => now(),
                'scheduled_at' => $scheduledAt,
                'external_id' => $result->externalId,
                'external_url' => $result->externalUrl,
                'api_response' => $result->rawResponse,
                'last_error' => null,
            ]);
        } else {
            $publication->update([
                'status' => PublicationStatus::Failed,
                'external_id' => $result->externalId,
                'api_response' => $result->rawResponse,
                'last_error' => $result->error,
            ]);
        }

        Log::info('social-article: publish finished', [
            'post_id' => $post->id,
            'platform' => $platform,
            'success' => $result->success,
        ]);

        return $publication->fresh();
    }

    private function buildMessage(WordpressPost $post, ?string $message): string
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

    private function dispatchPublish(
        string $platform,
        WordpressPost $post,
        string $message,
        ?Carbon $scheduledAt = null,
    ): PublishResult {
        if (str_starts_with($platform, 'facebook_')) {
            if ($scheduledAt !== null && $scheduledAt->isFuture()) {
                return $this->facebookLinks->scheduleLink($platform, $message, $post->url, $scheduledAt);
            }

            return $this->facebookLinks->publishLink($platform, $message, $post->url);
        }

        if (str_starts_with($platform, 'linkedin')) {
            $publisher = new LinkedInLinkPublisher($platform, new LinkedInTokenService($platform));

            return $publisher->publishLink(
                $message,
                $post->url,
                $post->title,
                $post->excerpt,
                $post->featured_image_url,
            );
        }

        return PublishResult::fail("Plataforma no soportada: {$platform}");
    }
}
