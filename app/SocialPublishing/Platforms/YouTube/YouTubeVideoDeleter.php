<?php

namespace App\SocialPublishing\Platforms\YouTube;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class YouTubeVideoDeleter
{
    public function __construct(
        private readonly YouTubeAccessToken $accessToken,
    ) {}

    public function delete(string $videoId): void
    {
        if (trim($videoId) === '') {
            throw new RuntimeException('No hay ID de video de YouTube para eliminar.');
        }

        $response = Http::withToken($this->accessToken->get())
            ->timeout(60)
            ->delete('https://www.googleapis.com/youtube/v3/videos', [
                'id' => $videoId,
            ]);

        if ($response->status() === 204 || $response->successful()) {
            return;
        }

        if ($response->status() === 404) {
            Log::info('youtube: video already removed (404)', ['video_id' => $videoId]);

            return;
        }

        $body = $response->json();

        if (is_array($body) && $this->isAlreadyGoneError($body)) {
            Log::info('youtube: video already removed on API', [
                'video_id' => $videoId,
                'reason' => $body['error']['errors'][0]['reason'] ?? null,
            ]);

            return;
        }

        throw new RuntimeException(
            'YouTube API al eliminar: '.$response->status().' — '.$response->body(),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function isAlreadyGoneError(array $body): bool
    {
        $error = $body['error'] ?? null;

        if (! is_array($error)) {
            return false;
        }

        $errors = $error['errors'] ?? [];

        if (! is_array($errors)) {
            return false;
        }

        foreach ($errors as $item) {
            if (! is_array($item)) {
                continue;
            }

            $reason = (string) ($item['reason'] ?? '');

            if (in_array($reason, ['videoNotFound', 'notFound'], true)) {
                return true;
            }
        }

        return false;
    }
}
