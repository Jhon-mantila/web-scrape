<?php

namespace App\SocialPublishing\Platforms\Facebook;

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
        $cfg = config("social.facebook.{$this->configKey}");

        return ! empty($cfg['page_id']) && ! empty($cfg['page_access_token']);
    }

    public function publish(SocialPublication $publication): PublishResult
    {
        if (! $this->isConfigured()) {
            return PublishResult::fail(
                "Facebook ({$this->configKey}) no configurado. Añade PAGE_ID y PAGE_TOKEN en .env."
            );
        }

        $video = $publication->video;
        $disk = Storage::disk('public');

        if (! $disk->exists($video->video_path)) {
            return PublishResult::fail('Archivo de video no encontrado.');
        }

        $pageId = config("social.facebook.{$this->configKey}.page_id");
        $token = config("social.facebook.{$this->configKey}.page_access_token");
        $videoUrl = $disk->url($video->video_path);

        // Nota: en producción el video debe ser URL pública accesible para Facebook.
        // Si storage es local, sube vía Graph resumable upload (fase 2) o expón URL pública.
        $caption = $publication->caption() ?? $video->title;

        try {
            $response = Http::post("https://graph.facebook.com/v21.0/{$pageId}/videos", [
                'file_url' => $videoUrl,
                'description' => $caption,
                'access_token' => $token,
            ]);

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
                $postId !== '' ? "https://www.facebook.com/{$postId}" : null,
                $data,
            );
        } catch (\Throwable $e) {
            return PublishResult::fail($e->getMessage());
        }
    }
}
