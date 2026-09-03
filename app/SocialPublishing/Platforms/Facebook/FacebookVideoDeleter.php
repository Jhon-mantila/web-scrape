<?php

namespace App\SocialPublishing\Platforms\Facebook;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FacebookVideoDeleter
{
    private const GRAPH_VERSION = 'v21.0';

    public function delete(string $videoId, string $pageAccessToken): void
    {
        if ($videoId === '') {
            throw new RuntimeException('No hay ID de video de Facebook para eliminar.');
        }

        $response = Http::timeout(60)->delete($this->graphUrl("/{$videoId}"), [
            'access_token' => $pageAccessToken,
        ]);

        $json = $response->json();

        if (is_array($json) && isset($json['error']) && is_array($json['error'])) {
            if ($this->isAlreadyGoneError($json['error'])) {
                Log::info('facebook: video already removed on Meta', [
                    'video_id' => $videoId,
                    'error_code' => $json['error']['code'] ?? null,
                ]);

                return;
            }
        }

        if ($response->status() === 404) {
            Log::info('facebook: video already removed on Meta (404)', [
                'video_id' => $videoId,
            ]);

            return;
        }

        FacebookGraphResponse::assertSuccessful($response, 'eliminar video');
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private function isAlreadyGoneError(array $error): bool
    {
        $code = (int) ($error['code'] ?? 0);
        $subcode = (int) ($error['error_subcode'] ?? 0);
        $message = strtolower((string) ($error['message'] ?? ''));

        if ($code === 803 || $subcode === 803) {
            return true;
        }

        if ($code !== 100) {
            return false;
        }

        return str_contains($message, 'does not exist')
            || str_contains($message, 'unsupported delete')
            || str_contains($message, 'no longer available')
            || str_contains($message, 'cannot be loaded');
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.self::GRAPH_VERSION.$path;
    }
}
