<?php

namespace App\SocialPublishing\Platforms\Facebook;

use App\Models\SocialPlatformAccount;
use App\Models\SocialPublication;
use App\SocialPublishing\Contracts\SocialPublisherInterface;
use App\SocialPublishing\DTO\PublishResult;
use App\SocialPublishing\Support\VideoFileSize;
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

            Log::info('facebook: publish', [
                'platform' => $this->platformKey,
                'dimensions' => $metadata->dimensionsLabel(),
                'content_type' => $metadata->contentType(),
            ]);

            if ($metadata->contentType() === 'reel') {
                return $this->publishAsReel(
                    $publication,
                    $pageId,
                    $token,
                    $preparedPath,
                    $caption,
                    $fileSize,
                    $metadata,
                );
            }

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

    private function publishAsReel(
        SocialPublication $publication,
        string $pageId,
        string $token,
        string $videoPath,
        string $caption,
        ?int $fileSize,
        FacebookVideoMetadata $metadata,
    ): PublishResult {
        $isScheduled = $publication->scheduled_at?->isFuture() ?? false;

        $finishParams = [
            'title' => mb_substr($publication->video->title, 0, 100),
            'description' => $caption,
            'video_state' => $isScheduled ? 'SCHEDULED' : 'PUBLISHED',
        ];

        if ($isScheduled && $publication->scheduled_at !== null) {
            $finishParams['scheduled_publish_time'] = $publication->scheduled_at->timestamp;
        }

        try {
            $upload = (new FacebookReelUpload)->upload($pageId, $token, $videoPath, $finishParams);
        } catch (\Throwable $e) {
            Log::warning('facebook: reel upload failed', [
                'platform' => $this->platformKey,
                'error' => $e->getMessage(),
            ]);

            return PublishResult::fail($e->getMessage(), $this->videoMeta($fileSize, $metadata));
        }

        return $this->finalizeReelAfterUpload(
            $upload['video_id'],
            is_array($upload['response']) ? $upload['response'] : [],
            $pageId,
            $token,
            $fileSize,
            $metadata,
            $isScheduled,
        );
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

        $finishParams = [
            'title' => mb_substr($publication->video->title, 0, 100),
            'description' => $caption,
            'published' => $isScheduled ? 'false' : 'true',
        ];

        if ($isScheduled && $publication->scheduled_at !== null) {
            $finishParams['scheduled_publish_time'] = $publication->scheduled_at->timestamp;
            $finishParams['unpublished_content_type'] = 'SCHEDULED';
        }

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
            $publication->scheduled_at,
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
        ?\Illuminate\Support\Carbon $scheduledAt,
    ): PublishResult {
        $draft = PublishResult::ok(
            $videoId,
            null,
            array_merge($createResponse, $this->videoMeta($fileSize, $metadata), [
                'upload_method' => $uploadMethod,
                'facebook_publish_method' => 'finish',
                'facebook_scheduled_for' => $scheduledAt?->toIso8601String(),
            ]),
        );

        $finalized = $this->finalizePublishedVideo(
            $draft,
            $pageId,
            $token,
            $metadata,
            $this->resolveThumbnailPath($disk, $thumbnailPath),
            $isScheduled,
        );

        if (! $finalized->success) {
            return $finalized;
        }

        $inspector = $this->videoInspector ?? new FacebookVideoInspector;

        try {
            if ($isScheduled) {
                $info = $inspector->waitForScheduledOnMeta($videoId, $token);

                return PublishResult::ok(
                    $videoId,
                    $finalized->externalUrl,
                    array_merge($finalized->rawResponse ?? [], [
                        'facebook_visibility_verified' => 'video_scheduled',
                    ], $this->videoInspectionMeta($info)),
                );
            }

            $info = $inspector->waitForPublicPublish($videoId, $token);
        } catch (\Throwable $e) {
            Log::warning('facebook: page video visibility not confirmed after finish', [
                'platform' => $this->platformKey,
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return PublishResult::fail(
                $e->getMessage(),
                array_merge($finalized->rawResponse ?? [], [
                    'facebook_video_id' => $videoId,
                ]),
                $videoId,
            );
        }

        $apiPermalink = is_string($info['permalink_url'] ?? null) ? $info['permalink_url'] : null;
        $permalink = FacebookVideoPermalink::build(
            $videoId,
            'page_video',
            $pageId,
            $apiPermalink ?? $finalized->externalUrl,
        );

        return PublishResult::ok(
            $videoId,
            $permalink !== '' ? $permalink : $finalized->externalUrl,
            array_merge($finalized->rawResponse ?? [], [
                'facebook_visibility_verified' => 'video_public',
            ], $this->videoInspectionMeta($info)),
        );
    }

    /**
     * @param  array<string, mixed>  $createResponse
     */
    private function finalizeReelAfterUpload(
        string $videoId,
        array $createResponse,
        string $pageId,
        string $token,
        ?int $fileSize,
        FacebookVideoMetadata $metadata,
        bool $isScheduled,
    ): PublishResult {
        $postId = is_string($createResponse['post_id'] ?? null) ? $createResponse['post_id'] : null;

        $draft = PublishResult::ok(
            $videoId,
            null,
            array_merge($createResponse, $this->videoMeta($fileSize, $metadata), [
                'upload_method' => 'reel',
                'facebook_post_id' => $postId,
            ]),
        );

        $finalized = $this->finalizePublishedVideo(
            $draft,
            $pageId,
            $token,
            $metadata,
            null,
            $isScheduled,
        );

        if (! $finalized->success) {
            return $finalized;
        }

        $inspector = $this->videoInspector ?? new FacebookVideoInspector;

        try {
            if ($isScheduled) {
                $info = $inspector->waitForScheduledOnMeta($videoId, $token);
                $permalink = $this->reelPermalink($videoId, $pageId, $postId, $info['permalink_url'] ?? null);

                return PublishResult::ok(
                    $videoId,
                    $permalink,
                    array_merge($finalized->rawResponse ?? [], [
                        'facebook_publish_method' => 'reel',
                        'facebook_visibility_verified' => 'reel_scheduled',
                    ], $this->videoInspectionMeta($info)),
                );
            }

            $info = $inspector->waitForPublicPublish($videoId, $token);
        } catch (\Throwable $e) {
            Log::warning('facebook: reel publish visibility not confirmed', [
                'platform' => $this->platformKey,
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return PublishResult::fail(
                $e->getMessage(),
                array_merge($finalized->rawResponse ?? [], [
                    'facebook_video_id' => $videoId,
                ]),
                $videoId,
            );
        }

        $permalink = $this->reelPermalink($videoId, $pageId, $postId, $info['permalink_url'] ?? null);

        return PublishResult::ok(
            $videoId,
            $permalink,
            array_merge($finalized->rawResponse ?? [], [
                'facebook_publish_method' => 'reel',
                'facebook_visibility_verified' => 'reel_public',
            ], $this->videoInspectionMeta($info)),
        );
    }

    private function reelPermalink(string $videoId, string $pageId, ?string $postId, mixed $apiPermalink): string
    {
        if ($postId !== null && $postId !== '') {
            return FacebookPageFeedScheduler::postUrl($postId);
        }

        return FacebookVideoPermalink::build(
            $videoId,
            'reel',
            $pageId,
            is_string($apiPermalink) ? $apiPermalink : null,
        );
    }

    private function finalizePublishedVideo(
        PublishResult $result,
        string $pageId,
        string $token,
        FacebookVideoMetadata $metadata,
        ?string $thumbnailFullPath = null,
        bool $isScheduled = false,
    ): PublishResult {
        if (! $result->success || $result->externalId === null || $result->externalId === '') {
            return $result;
        }

        try {
            $inspector = $this->videoInspector ?? new FacebookVideoInspector;
            $contentType = $metadata->contentType();
            $info = $inspector->waitForReady($result->externalId, $token, $contentType);

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

            if ($isScheduled) {
                if ($inspector->isPubliclyPublished($info)) {
                    Log::info('facebook: processed video already public before schedule step', [
                        'platform' => $this->platformKey,
                        'video_id' => $result->externalId,
                        'publish_status' => $info['publish_status'],
                    ]);
                } elseif ($inspector->isScheduledOnMeta($info)) {
                    Log::info('facebook: processed video already scheduled on Meta', [
                        'platform' => $this->platformKey,
                        'video_id' => $result->externalId,
                        'publish_status' => $info['publish_status'],
                    ]);
                }
            } elseif ($inspector->isScheduledOnMeta($info)) {
                return PublishResult::fail(
                    'Meta dejó el video programado pero se pidió publicación inmediata (publish_status=scheduled). '
                    .'Elimínalo en Facebook e inténtalo de nuevo.',
                    array_merge($result->rawResponse ?? [], $this->videoInspectionMeta($info), [
                        'facebook_video_id' => $result->externalId,
                    ]),
                    $result->externalId,
                );
            } elseif ($inspector->isPubliclyPublished($info)) {
                Log::info('facebook: processed video already public before publish step', [
                    'platform' => $this->platformKey,
                    'video_id' => $result->externalId,
                    'publish_status' => $info['publish_status'],
                ]);
            }

            $thumbnailUpload = null;

            if ($contentType === 'page_video') {
                $thumbnailUpload = ($this->thumbnailUploader ?? new FacebookVideoThumbnailUploader)
                    ->upload($result->externalId, $token, $thumbnailFullPath);
            }

            $apiPermalink = is_string($info['permalink_url'] ?? null) ? $info['permalink_url'] : null;
            $permalink = FacebookVideoPermalink::build(
                $result->externalId,
                $contentType,
                $pageId,
                $apiPermalink,
            );

            return PublishResult::ok(
                $result->externalId,
                $permalink,
                array_merge($result->rawResponse ?? [], $this->videoInspectionMeta($info), [
                    'facebook_permalink_api' => $apiPermalink,
                ], $thumbnailUpload !== null ? ['thumbnail_upload' => $thumbnailUpload] : []),
            );
        } catch (\Throwable $e) {
            Log::warning('facebook: video inspection failed', [
                'platform' => $this->platformKey,
                'video_id' => $result->externalId,
                'content_type' => $contentType,
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

    /**
     * @param  array{
     *     video_status?: ?string,
     *     published?: mixed,
     *     publish_status?: ?string,
     *     embed_is_reel?: bool,
     *     status?: mixed,
     *     permalink_url?: mixed
     * }  $info
     * @return array<string, mixed>
     */
    private function videoInspectionMeta(array $info): array
    {
        return [
            'facebook_video_status' => $info['video_status'] ?? null,
            'facebook_published' => $info['published'] ?? null,
            'facebook_publish_status' => $info['publish_status'] ?? null,
            'facebook_embed_is_reel' => $info['embed_is_reel'] ?? false,
            'facebook_processing_status' => $info['status'] ?? null,
            'facebook_permalink_api' => $info['permalink_url'] ?? null,
        ];
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
            'content_type' => $metadata->contentType(),
            'content_type_label' => $metadata->contentTypeLabel(),
            'video_width' => $metadata->width,
            'video_height' => $metadata->height,
            'video_dimensions' => $metadata->dimensionsLabel(),
        ]);
    }
}
