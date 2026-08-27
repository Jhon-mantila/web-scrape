<?php

namespace App\SocialPublishing\Platforms\Facebook;

use App\Models\SocialPlatformAccount;
use App\Models\SocialPublication;
use App\SocialPublishing\Contracts\SocialPublisherInterface;
use App\SocialPublishing\DTO\PublishResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FacebookPublisher implements SocialPublisherInterface
{
    public function __construct(
        private readonly string $platformKey,
        private readonly string $configKey,
    ) {}

    public function platform(): string
    {
        return $this->platformKey;
    }

    public function isConfigured(): bool
    {
        return $this->pageCredentials() !== null;
    }

    public function publish(SocialPublication $publication): PublishResult
    {
        $credentials = $this->pageCredentials();

        if ($credentials === null) {
            return PublishResult::fail(
                "Facebook ({$this->configKey}) no conectado. Ve a Configuración o añade PAGE_ID y PAGE_TOKEN en .env."
            );
        }

        $video = $publication->video;
        $disk = Storage::disk('public');

        if (! $disk->exists($video->video_path)) {
            return PublishResult::fail('Archivo de video no encontrado.');
        }

        $pageId = $credentials['page_id'];
        $token = $credentials['page_access_token'];
        $videoPath = $disk->path($video->video_path);
        $caption = $publication->caption() ?? $video->title;

        try {
            $request = Http::timeout(600)
                ->attach(
                    'source',
                    fopen($videoPath, 'r'),
                    basename($videoPath),
                    ['Content-Type' => 'video/mp4'],
                );

            if ($disk->exists($video->thumbnail_path)) {
                $thumbPath = $disk->path($video->thumbnail_path);
                $thumbMime = mime_content_type($thumbPath) ?: 'image/jpeg';

                $request = $request->attach(
                    'thumb',
                    fopen($thumbPath, 'r'),
                    basename($thumbPath),
                    ['Content-Type' => $thumbMime],
                );
            }

            $response = $request->post(
                "https://graph-video.facebook.com/v21.0/{$pageId}/videos",
                array_merge(
                    [
                        'access_token' => $token,
                        'description' => $caption,
                        'title' => mb_substr($video->title, 0, 100),
                    ],
                    $this->buildScheduleParams($publication),
                ),
            );

            if ($response->failed()) {
                Log::warning('facebook: upload failed', [
                    'platform' => $this->platformKey,
                    'body' => $response->body(),
                ]);

                return PublishResult::fail(
                    'Facebook API: '.$response->status().' — '.$response->body(),
                    $response->json(),
                );
            }

            $data = $response->json();
            $postId = (string) ($data['id'] ?? '');

            return PublishResult::ok(
                $postId,
                $postId !== '' ? 'https://www.facebook.com/watch/?v='.$postId : null,
                $data,
            );
        } catch (\Throwable $e) {
            return PublishResult::fail($e->getMessage());
        }
    }

    /**
     * @return array{page_id: string, page_access_token: string, page_name?: string}|null
     */
    private function pageCredentials(): ?array
    {
        $fromDb = SocialPlatformAccount::facebookPageCredentials($this->platformKey);

        if ($fromDb !== null) {
            return $fromDb;
        }

        $cfg = config("social.facebook.{$this->configKey}");

        if (! is_array($cfg) || empty($cfg['page_id']) || empty($cfg['page_access_token'])) {
            return null;
        }

        return [
            'page_id' => (string) $cfg['page_id'],
            'page_access_token' => (string) $cfg['page_access_token'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildScheduleParams(SocialPublication $publication): array
    {
        $scheduledAt = $publication->scheduled_at;

        if ($scheduledAt === null || ! $scheduledAt->isFuture()) {
            return [];
        }

        return [
            'published' => 'false',
            'scheduled_publish_time' => $scheduledAt->timestamp,
        ];
    }
}
