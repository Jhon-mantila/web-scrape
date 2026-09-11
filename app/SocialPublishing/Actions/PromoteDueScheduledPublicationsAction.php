<?php

namespace App\SocialPublishing\Actions;

use App\Models\SocialPlatformAccount;
use App\Models\SocialPublication;
use App\Models\SocialVideo;
use App\SocialPublishing\Enums\PublicationStatus;
use App\SocialPublishing\Platforms\Facebook\FacebookPageFeedScheduler;
use App\SocialPublishing\Platforms\Facebook\FacebookVideoPermalink;

/**
 * Marca como publicadas las plataformas programadas cuya fecha ya pasó (sin consultar APIs).
 */
class PromoteDueScheduledPublicationsAction
{
    public function execute(SocialVideo $video): void
    {
        foreach ($video->publications as $publication) {
            $this->promoteIfDue($publication);
        }
    }

    private function promoteIfDue(SocialPublication $publication): void
    {
        if ($publication->status !== PublicationStatus::Scheduled) {
            return;
        }

        $scheduledAt = $publication->scheduled_at;

        if ($scheduledAt === null || $scheduledAt->isFuture()) {
            return;
        }

        $apiResponse = is_array($publication->api_response) ? $publication->api_response : [];

        $publication->update([
            'status' => PublicationStatus::Published,
            'published_at' => $publication->published_at ?? $scheduledAt,
            'external_url' => $publication->external_url ?? $this->resolveExternalUrl($publication),
            'api_response' => array_merge($apiResponse, [
                'promoted_at' => now()->toIso8601String(),
                'promoted_reason' => 'scheduled_at_due',
            ]),
        ]);
    }

    private function resolveExternalUrl(SocialPublication $publication): ?string
    {
        $externalId = $publication->external_id;

        if ($externalId === null || $externalId === '') {
            return null;
        }

        if (str_starts_with($publication->platform, 'facebook_')) {
            return $this->facebookUrl($publication, $externalId);
        }

        if ($publication->platform === 'youtube') {
            return 'https://www.youtube.com/watch?v='.$externalId;
        }

        return null;
    }

    private function facebookUrl(SocialPublication $publication, string $videoId): ?string
    {
        $apiResponse = is_array($publication->api_response) ? $publication->api_response : [];
        $postId = is_string($apiResponse['facebook_post_id'] ?? null) ? $apiResponse['facebook_post_id'] : null;

        if ($postId !== null && $postId !== '') {
            $postPermalink = is_string($apiResponse['facebook_post_permalink'] ?? null)
                ? $apiResponse['facebook_post_permalink']
                : null;

            if ($postPermalink !== null && $postPermalink !== '') {
                return FacebookVideoPermalink::build($videoId, 'page_video', null, $postPermalink);
            }

            return FacebookPageFeedScheduler::postUrl($postId);
        }

        $contentType = is_string($apiResponse['content_type'] ?? null)
            ? $apiResponse['content_type']
            : 'page_video';

        $credentials = SocialPlatformAccount::facebookPageCredentials($publication->platform);
        $pageId = is_array($credentials) ? ($credentials['page_id'] ?? null) : null;

        if ($pageId === null || $pageId === '') {
            $configKey = str_replace('facebook_', '', $publication->platform);
            $pageId = config("social.facebook.{$configKey}.page_id");
        }

        $apiPermalink = is_string($apiResponse['facebook_permalink_api'] ?? null)
            ? $apiResponse['facebook_permalink_api']
            : null;

        return FacebookVideoPermalink::build(
            $videoId,
            $contentType,
            is_string($pageId) ? $pageId : null,
            $apiPermalink,
        );
    }
}
