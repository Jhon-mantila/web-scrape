<?php

namespace App\SocialPublishing\Platforms\Facebook;

use App\Models\SocialPlatformAccount;
use App\SocialPublishing\DTO\PublishResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookLinkPublisher
{
    private const GRAPH_VERSION = 'v21.0';

    public function publishLink(string $platformKey, string $message, string $link): PublishResult
    {
        $credentials = SocialPlatformAccount::facebookPageCredentials($platformKey);

        if ($credentials === null) {
            return PublishResult::fail("Facebook ({$platformKey}) no conectado. Ve a Configuración.");
        }

        try {
            $response = Http::timeout(60)->asForm()->post($this->graphUrl('/'.$credentials['page_id'].'/feed'), [
                'access_token' => $credentials['page_access_token'],
                'message' => $message,
                'link' => $link,
                'published' => 'true',
            ]);

            FacebookGraphResponse::assertSuccessful($response, 'publicar enlace en el muro');

            $postId = (string) ($response->json('id') ?? '');

            if ($postId === '') {
                return PublishResult::fail('Facebook no devolvió ID de la publicación.');
            }

            Log::info('facebook: link post published', [
                'platform' => $platformKey,
                'post_id' => $postId,
                'link' => $link,
            ]);

            return PublishResult::ok(
                $postId,
                FacebookPageFeedScheduler::postUrl($postId),
                $response->json(),
            );
        } catch (\Throwable $e) {
            return PublishResult::fail($e->getMessage());
        }
    }

    public function scheduleLink(
        string $platformKey,
        string $message,
        string $link,
        Carbon $scheduledAt,
    ): PublishResult {
        $credentials = SocialPlatformAccount::facebookPageCredentials($platformKey);

        if ($credentials === null) {
            return PublishResult::fail("Facebook ({$platformKey}) no conectado. Ve a Configuración.");
        }

        try {
            $response = Http::timeout(60)->asForm()->post($this->graphUrl('/'.$credentials['page_id'].'/feed'), [
                'access_token' => $credentials['page_access_token'],
                'message' => $message,
                'link' => $link,
                'published' => 'false',
                'scheduled_publish_time' => $scheduledAt->timestamp,
                'unpublished_content_type' => 'SCHEDULED',
            ]);

            FacebookGraphResponse::assertSuccessful($response, 'programar enlace en el muro');

            $postId = (string) ($response->json('id') ?? '');

            if ($postId === '') {
                return PublishResult::fail('Facebook no devolvió ID de la publicación programada.');
            }

            Log::info('facebook: link post scheduled', [
                'platform' => $platformKey,
                'post_id' => $postId,
                'link' => $link,
                'scheduled_at' => $scheduledAt->toIso8601String(),
            ]);

            return PublishResult::ok(
                $postId,
                FacebookPageFeedScheduler::postUrl($postId),
                $response->json(),
            );
        } catch (\Throwable $e) {
            return PublishResult::fail($e->getMessage());
        }
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.self::GRAPH_VERSION.$path;
    }
}
