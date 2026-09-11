<?php

namespace App\SocialPublishing\Platforms\Facebook;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Programa una publicación de muro con video adjunto (como Meta Business Suite).
 *
 * @see https://developers.facebook.com/docs/pages-api/posts/
 */
class FacebookPageFeedScheduler
{
    private const GRAPH_VERSION = 'v21.0';

    /**
     * Publica de inmediato en el muro con el video adjunto (visible para seguidores).
     *
     * @return array{post_id: string, response: mixed}
     */
    public function publishVideoPostNow(
        string $pageId,
        string $accessToken,
        string $videoId,
        string $message,
    ): array {
        $response = Http::timeout(60)->asForm()->post($this->graphUrl("/{$pageId}/feed"), [
            'access_token' => $accessToken,
            'message' => $message,
            'published' => 'true',
            'attached_media[0]' => json_encode(['media_fbid' => $videoId], JSON_THROW_ON_ERROR),
        ]);

        FacebookGraphResponse::assertSuccessful($response, 'publicar video en el muro');

        $postId = (string) ($response->json('id') ?? '');

        if ($postId === '') {
            throw new \RuntimeException('Facebook no devolvió ID de la publicación en el muro.');
        }

        Log::info('facebook: feed post published now', [
            'page_id' => $pageId,
            'video_id' => $videoId,
            'post_id' => $postId,
        ]);

        return [
            'post_id' => $postId,
            'response' => $response->json(),
        ];
    }

    /**
     * @return array{post_id: string, response: mixed}
     */
    public function scheduleVideoPost(
        string $pageId,
        string $accessToken,
        string $videoId,
        string $message,
        Carbon $scheduledAt,
    ): array {
        $response = Http::timeout(60)->asForm()->post($this->graphUrl("/{$pageId}/feed"), [
            'access_token' => $accessToken,
            'message' => $message,
            'published' => 'false',
            'scheduled_publish_time' => $scheduledAt->timestamp,
            'unpublished_content_type' => 'SCHEDULED',
            'attached_media[0]' => json_encode(['media_fbid' => $videoId], JSON_THROW_ON_ERROR),
        ]);

        FacebookGraphResponse::assertSuccessful($response, 'programar publicación en el muro');

        $postId = (string) ($response->json('id') ?? '');

        if ($postId === '') {
            throw new \RuntimeException('Facebook no devolvió ID de la publicación programada.');
        }

        Log::info('facebook: feed post scheduled', [
            'page_id' => $pageId,
            'video_id' => $videoId,
            'post_id' => $postId,
            'scheduled_at' => $scheduledAt->toIso8601String(),
        ]);

        return [
            'post_id' => $postId,
            'response' => $response->json(),
        ];
    }

    public static function postUrl(string $postId): string
    {
        return 'https://www.facebook.com/'.ltrim($postId, '/');
    }

    /**
     * @return array{is_published: bool, permalink_url: ?string}|null
     */
    public function inspectPost(string $postId, string $accessToken): ?array
    {
        if ($postId === '') {
            return null;
        }

        $response = Http::timeout(60)->get($this->graphUrl('/'.$postId), [
            'access_token' => $accessToken,
            'fields' => 'is_published,permalink_url',
        ]);

        FacebookGraphResponse::assertSuccessful($response, 'consultar publicación programada');

        return [
            'is_published' => (bool) $response->json('is_published'),
            'permalink_url' => $response->json('permalink_url'),
        ];
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.self::GRAPH_VERSION.$path;
    }
}
