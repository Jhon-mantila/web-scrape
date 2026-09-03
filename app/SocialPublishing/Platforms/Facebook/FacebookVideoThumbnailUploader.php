<?php

namespace App\SocialPublishing\Platforms\Facebook;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FacebookVideoThumbnailUploader
{
    private const GRAPH_VERSION = 'v21.0';

    /**
     * @return array{ok: bool, error?: string, host?: string}|null
     */
    public function upload(string $videoId, string $accessToken, ?string $thumbPath): ?array
    {
        if ($thumbPath === null || $thumbPath === '' || ! is_file($thumbPath)) {
            return null;
        }

        $thumbMime = mime_content_type($thumbPath) ?: 'image/jpeg';
        $fileName = basename($thumbPath);

        foreach (['graph-video', 'graph'] as $host) {
            try {
                $response = $this->postThumb($host, $videoId, $accessToken, $thumbPath, $fileName, $thumbMime);
                FacebookGraphResponse::assertSuccessful($response, 'subir miniatura del video');

                Log::info('facebook: thumbnail uploaded', [
                    'video_id' => $videoId,
                    'host' => $host,
                ]);

                return ['ok' => true, 'host' => $host];
            } catch (\Throwable $e) {
                Log::warning('facebook: thumbnail upload attempt failed', [
                    'video_id' => $videoId,
                    'host' => $host,
                    'error' => $e->getMessage(),
                ]);
                $lastError = $e->getMessage();
            }
        }

        return [
            'ok' => false,
            'error' => $lastError ?? 'No se pudo subir la miniatura.',
        ];
    }

    private function postThumb(
        string $host,
        string $videoId,
        string $accessToken,
        string $thumbPath,
        string $fileName,
        string $thumbMime,
    ): Response {
        $url = $host === 'graph-video'
            ? 'https://graph-video.facebook.com/'.self::GRAPH_VERSION."/{$videoId}"
            : 'https://graph.facebook.com/'.self::GRAPH_VERSION."/{$videoId}";

        return Http::timeout(120)
            ->attach('thumb', fopen($thumbPath, 'r'), $fileName, ['Content-Type' => $thumbMime])
            ->post($url, [
                'access_token' => $accessToken,
            ]);
    }
}
