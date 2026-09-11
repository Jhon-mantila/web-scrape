<?php

namespace App\SocialPublishing\Actions;

use App\Models\SocialPlatformAccount;
use App\Models\SocialPublication;
use App\Models\SocialVideo;
use App\SocialPublishing\Enums\PublicationStatus;
use App\SocialPublishing\Platforms\Facebook\FacebookPageFeedScheduler;
use App\SocialPublishing\Platforms\Facebook\FacebookVideoInspector;
use App\SocialPublishing\Platforms\Facebook\FacebookVideoPermalink;

/**
 * Sincroniza estado con Meta al abrir un video (sin cron 24/7).
 */
class SyncFacebookScheduledPublicationsAction
{
    public function execute(SocialVideo $video): void
    {
        foreach ($video->publications as $publication) {
            $this->syncPublication($publication);
        }
    }

    private function syncPublication(SocialPublication $publication): void
    {
        if ($publication->status !== PublicationStatus::Scheduled) {
            return;
        }

        if (! str_starts_with($publication->platform, 'facebook_')) {
            return;
        }

        $credentials = SocialPlatformAccount::facebookPageCredentials($publication->platform);

        if ($credentials === null) {
            return;
        }

        $apiResponse = is_array($publication->api_response) ? $publication->api_response : [];
        $postId = is_string($apiResponse['facebook_post_id'] ?? null) ? $apiResponse['facebook_post_id'] : null;

        if ($postId !== null && $postId !== '') {
            $this->syncFeedPost($publication, $postId, $credentials['page_access_token'], $apiResponse);

            return;
        }

        $this->syncVideo($publication, $credentials, $apiResponse);
    }

    /**
     * @param  array<string, mixed>  $apiResponse
     */
    private function syncFeedPost(
        SocialPublication $publication,
        string $postId,
        string $token,
        array $apiResponse,
    ): void {
        try {
            $info = (new FacebookPageFeedScheduler)->inspectPost($postId, $token);
        } catch (\Throwable) {
            return;
        }

        if ($info === null || ! $info['is_published']) {
            return;
        }

        $permalink = is_string($info['permalink_url'] ?? null) && $info['permalink_url'] !== ''
            ? FacebookVideoPermalink::build(
                (string) $publication->external_id,
                'page_video',
                null,
                $info['permalink_url'],
            )
            : FacebookPageFeedScheduler::postUrl($postId);

        $publication->update([
            'status' => PublicationStatus::Published,
            'published_at' => $publication->published_at ?? now(),
            'external_url' => $permalink,
            'api_response' => array_merge($apiResponse, [
                'facebook_post_published' => true,
                'facebook_post_permalink' => $info['permalink_url'],
                'facebook_synced_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    /**
     * @param  array{page_id: string, page_access_token: string}  $credentials
     * @param  array<string, mixed>  $apiResponse
     */
    private function syncVideo(SocialPublication $publication, array $credentials, array $apiResponse): void
    {
        $videoId = $publication->external_id;

        if ($videoId === null || $videoId === '') {
            return;
        }

        try {
            $inspector = new FacebookVideoInspector;
            $info = $inspector->inspect($videoId, $credentials['page_access_token']);
        } catch (\Throwable) {
            return;
        }

        if (! $inspector->isPubliclyPublished($info)) {
            return;
        }

        $apiPermalink = is_string($info['permalink_url'] ?? null) ? $info['permalink_url'] : null;
        $permalink = FacebookVideoPermalink::build(
            $videoId,
            is_string($apiResponse['content_type'] ?? null) ? $apiResponse['content_type'] : 'page_video',
            $credentials['page_id'],
            $apiPermalink,
        );

        $publication->update([
            'status' => PublicationStatus::Published,
            'published_at' => $publication->published_at ?? now(),
            'external_url' => $permalink,
            'api_response' => array_merge($apiResponse, [
                'facebook_video_status' => $info['video_status'],
                'facebook_published' => $info['published'],
                'facebook_publish_status' => $info['publish_status'],
                'facebook_embed_is_reel' => $info['embed_is_reel'],
                'facebook_processing_status' => $info['status'],
                'facebook_permalink_api' => $apiPermalink,
                'facebook_synced_at' => now()->toIso8601String(),
            ]),
        ]);
    }
}
