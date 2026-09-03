<?php

namespace App\SocialPublishing\Platforms\Facebook;

use App\Models\SocialPlatformAccount;
use App\Models\SocialPublication;
use App\SocialPublishing\Contracts\SocialPublisherInterface;
use App\SocialPublishing\DTO\PublishResult;
use App\SocialPublishing\Support\VideoFileSize;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Publica videos de página en Facebook (/{page-id}/videos), con programación y miniatura.
 *
 * @see https://developers.facebook.com/docs/video-api/guides/publishing/
 */
class FacebookPublisher implements SocialPublisherInterface
{
    private const MAX_VIDEO_BYTES = 2 * 1024 * 1024 * 1024;

    private const CONTENT_TYPE = 'page_video';

    public function __construct(
        private readonly string $platformKey,
        private readonly string $configKey,
        private readonly ?FacebookPageVideoChunkedUpload $pageVideoUpload = null,
        private readonly ?FacebookVideoInspector $videoInspector = null,
        private readonly ?FacebookVideoThumbnailUploader $thumbnailUploader = null,
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

        $preparer = new FacebookVideoPreparer;
        $preparedPath = $preparer->prepare($videoPath);
        $fileSize = VideoFileSize::bytesFromPath($preparedPath);

        if ($fileSize !== null && $fileSize > self::MAX_VIDEO_BYTES) {
            $preparer->cleanup($videoPath, $preparedPath);

            return PublishResult::fail(
                sprintf(
                    'El video pesa %s. La API de Facebook admite hasta 2 GB por video.',
                    VideoFileSize::label($fileSize),
                ),
                $this->sizeMeta($fileSize),
            );
        }

        try {
            $metadata = FacebookVideoMetadata::fromPath($preparedPath);

            Log::info('facebook: publish page video', [
                'platform' => $this->platformKey,
                'dimensions' => $metadata->dimensionsLabel(),
            ]);

            return $this->publishAsPageVideo(
                $publication,
                $pageId,
                $token,
                $preparedPath,
                $caption,
                $disk,
                $video->thumbnail_path,
                $fileSize,
                $metadata,
            );
        } catch (RuntimeException $e) {
            return PublishResult::fail($e->getMessage(), $this->sizeMeta($fileSize));
        } catch (\Throwable $e) {
            return PublishResult::fail($e->getMessage(), $this->sizeMeta($fileSize));
        } finally {
            $preparer->cleanup($videoPath, $preparedPath);
        }
    }

    private function publishAsPageVideo(
        SocialPublication $publication,
        string $pageId,
        string $token,
        string $videoPath,
        string $caption,
        $disk,
        ?string $thumbnailPath,
        ?int $fileSize,
        FacebookVideoMetadata $metadata,
    ): PublishResult {
        $uploader = $this->pageVideoUpload ?? new FacebookPageVideoChunkedUpload;
        $isScheduled = $publication->scheduled_at?->isFuture() ?? false;

        $finishParams = array_merge(
            [
                'title' => mb_substr($publication->video->title, 0, 100),
                'description' => $caption,
                'published' => 'false',
            ],
            $isScheduled
                ? ['scheduled_publish_time' => $publication->scheduled_at->timestamp]
                : [],
        );

        try {
            $thumbFullPath = $this->resolveThumbnailPath($disk, $thumbnailPath);

            $upload = $uploader->upload($pageId, $token, $videoPath, $finishParams, $thumbFullPath);
        } catch (\Throwable $e) {
            Log::warning('facebook: page video upload failed', [
                'platform' => $this->platformKey,
                'error' => $e->getMessage(),
            ]);

            return PublishResult::fail($e->getMessage(), $this->videoMeta($fileSize, $metadata));
        }

        return $this->finalizeAfterUpload(
            $upload['video_id'],
            is_array($upload['response']) ? $upload['response'] : [],
            $pageId,
            $token,
            $disk,
            $thumbnailPath,
            $fileSize,
            $metadata,
            $isScheduled,
            'page_video_chunked',
        );
    }

    private function resolveThumbnailPath($disk, ?string $thumbnailPath): ?string
    {
        if ($thumbnailPath === null || $thumbnailPath === '' || ! $disk->exists($thumbnailPath)) {
            return null;
        }

        return $disk->path($thumbnailPath);
    }

    /**
     * @param  array<string, mixed>  $createResponse
     */
    private function finalizeAfterUpload(
        string $videoId,
        array $createResponse,
        string $pageId,
        string $token,
        $disk,
        ?string $thumbnailPath,
        ?int $fileSize,
        FacebookVideoMetadata $metadata,
        bool $isScheduled,
        string $uploadMethod,
    ): PublishResult {
        $draft = PublishResult::ok(
            $videoId,
            null,
            array_merge($createResponse, $this->videoMeta($fileSize, $metadata), [
                'upload_method' => $uploadMethod,
            ]),
        );

        $finalized = $this->finalizePublishedVideo(
            $draft,
            $pageId,
            $token,
            $metadata,
            $this->resolveThumbnailPath($disk, $thumbnailPath),
        );

        if (! $finalized->success || $isScheduled) {
            return $finalized;
        }

        return $this->publishVideoNow($finalized, $token);
    }

    private function publishVideoNow(PublishResult $result, string $token): PublishResult
    {
        if ($result->externalId === null || $result->externalId === '') {
            return $result;
        }

        $response = Http::timeout(60)->post($this->graphUrl("/{$result->externalId}"), [
            'access_token' => $token,
            'published' => 'true',
        ]);

        try {
            FacebookGraphResponse::assertSuccessful($response, 'publicar video en el muro');
        } catch (\Throwable $e) {
            return PublishResult::fail(
                'Video procesado pero no se pudo publicar en la página: '.$e->getMessage(),
                array_merge($result->rawResponse ?? [], ['facebook_video_id' => $result->externalId]),
                $result->externalId,
            );
        }

        return PublishResult::ok(
            $result->externalId,
            $result->externalUrl,
            array_merge($result->rawResponse ?? [], [
                'facebook_published_now' => true,
                'publish_response' => $response->json(),
            ]),
        );
    }

    private function finalizePublishedVideo(
        PublishResult $result,
        string $pageId,
        string $token,
        FacebookVideoMetadata $metadata,
        ?string $thumbnailFullPath = null,
    ): PublishResult {
        if (! $result->success || $result->externalId === null || $result->externalId === '') {
            return $result;
        }

        try {
            $inspector = $this->videoInspector ?? new FacebookVideoInspector;
            $info = $inspector->waitForReady($result->externalId, $token, self::CONTENT_TYPE);

            if (($info['video_status'] ?? null) !== 'ready') {
                return PublishResult::fail(
                    'Facebook no terminó de procesar el video (quedó vacío o gris en el planificador). '
                    .'Usa «Eliminar de Facebook» y vuelve a enviar.',
                    array_merge($result->rawResponse ?? [], [
                        'facebook_video_status' => $info['video_status'] ?? null,
                        'facebook_video_id' => $result->externalId,
                    ]),
                    $result->externalId,
                );
            }

            $thumbnailUpload = ($this->thumbnailUploader ?? new FacebookVideoThumbnailUploader)
                ->upload($result->externalId, $token, $thumbnailFullPath);

            $apiPermalink = is_string($info['permalink_url'] ?? null) ? $info['permalink_url'] : null;
            $permalink = FacebookVideoPermalink::build(
                $result->externalId,
                self::CONTENT_TYPE,
                $pageId,
                $apiPermalink,
            );

            return PublishResult::ok(
                $result->externalId,
                $permalink,
                array_merge($result->rawResponse ?? [], [
                    'facebook_video_status' => $info['video_status'],
                    'facebook_published' => $info['published'],
                    'facebook_processing_status' => $info['status'],
                    'facebook_permalink_api' => $apiPermalink,
                ], $thumbnailUpload !== null ? ['thumbnail_upload' => $thumbnailUpload] : []),
            );
        } catch (\Throwable $e) {
            Log::warning('facebook: video inspection failed', [
                'platform' => $this->platformKey,
                'video_id' => $result->externalId,
                'content_type' => self::CONTENT_TYPE,
                'error' => $e->getMessage(),
            ]);

            return PublishResult::fail(
                $e->getMessage(),
                array_merge($result->rawResponse ?? [], [
                    'facebook_video_id' => $result->externalId,
                ]),
                $result->externalId,
            );
        }
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/v21.0'.$path;
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
     * @return array<string, int|float|string|null>
     */
    private function sizeMeta(?int $fileSize): array
    {
        return [
            'video_size_bytes' => $fileSize,
            'video_size_mb' => VideoFileSize::megabytes($fileSize),
            'video_size_label' => VideoFileSize::label($fileSize),
            'facebook_max_video_gb' => 2,
        ];
    }

    /**
     * @return array<string, int|float|string|null>
     */
    private function videoMeta(?int $fileSize, FacebookVideoMetadata $metadata): array
    {
        return array_merge($this->sizeMeta($fileSize), [
            'content_type' => self::CONTENT_TYPE,
            'content_type_label' => 'Video de página',
            'video_width' => $metadata->width,
            'video_height' => $metadata->height,
            'video_dimensions' => $metadata->dimensionsLabel(),
        ]);
    }
}
