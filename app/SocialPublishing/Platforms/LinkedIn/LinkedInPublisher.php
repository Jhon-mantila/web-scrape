<?php

namespace App\SocialPublishing\Platforms\LinkedIn;

use App\Models\SocialPlatformAccount;
use App\Models\SocialPublication;
use App\SocialPublishing\Contracts\SocialPublisherInterface;
use App\SocialPublishing\DTO\PublishResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LinkedInPublisher implements SocialPublisherInterface
{
    public function __construct(
        private readonly string $platformKey,
        private readonly LinkedInTokenService $tokens,
    ) {}

    public function platform(): string
    {
        return $this->platformKey;
    }

    public function isConfigured(): bool
    {
        return SocialPlatformAccount::linkedinCredentials($this->platformKey) !== null;
    }

    public function publish(SocialPublication $publication): PublishResult
    {
        if (! $this->isConfigured()) {
            return PublishResult::fail(
                "LinkedIn ({$this->platformKey}) no conectado. Ve a Configuración y conecta el perfil."
            );
        }

        $video = $publication->video;
        $disk = Storage::disk('public');

        if (! $disk->exists($video->video_path)) {
            return PublishResult::fail('Archivo de video no encontrado en storage.');
        }

        $videoPath = $disk->path($video->video_path);
        $caption = $publication->caption() ?? $video->title;

        try {
            $personUrn = $this->tokens->personUrn();
            $headers = $this->tokens->apiHeaders();
            $fileSize = filesize($videoPath);

            if ($fileSize === false || $fileSize <= 0) {
                return PublishResult::fail('No se pudo leer el tamaño del video.');
            }

            $initResponse = LinkedInHttpClient::api($headers)
                ->post('https://api.linkedin.com/rest/videos?action=initializeUpload', [
                    'initializeUploadRequest' => [
                        'owner' => $personUrn,
                        'fileSizeBytes' => $fileSize,
                        'uploadCaptions' => false,
                        'uploadThumbnail' => false,
                    ],
                ]);

            if ($initResponse->failed()) {
                Log::warning('linkedin: initializeUpload failed', ['body' => $initResponse->body()]);

                return PublishResult::fail(
                    'LinkedIn initializeUpload: '.$initResponse->status().' — '.$initResponse->body(),
                    $initResponse->json(),
                );
            }

            $init = $initResponse->json('value');

            if (! is_array($init)) {
                return PublishResult::fail('LinkedIn no devolvió datos de subida.', $initResponse->json());
            }

            $videoUrn = (string) ($init['video'] ?? '');
            $uploadToken = (string) ($init['uploadToken'] ?? '');
            $instructions = $init['uploadInstructions'] ?? [];

            if ($videoUrn === '' || ! is_array($instructions) || $instructions === []) {
                return PublishResult::fail('LinkedIn devolvió instrucciones de subida inválidas.', $init);
            }

            $uploadedPartIds = $this->uploadVideoParts($videoPath, $instructions);

            $finalizeResponse = LinkedInHttpClient::api($headers)
                ->post('https://api.linkedin.com/rest/videos?action=finalizeUpload', [
                    'finalizeUploadRequest' => [
                        'video' => $videoUrn,
                        'uploadToken' => $uploadToken,
                        'uploadedPartIds' => $uploadedPartIds,
                    ],
                ]);

            if ($finalizeResponse->failed()) {
                Log::warning('linkedin: finalizeUpload failed', ['body' => $finalizeResponse->body()]);

                return PublishResult::fail(
                    'LinkedIn finalizeUpload: '.$finalizeResponse->status().' — '.$finalizeResponse->body(),
                    $finalizeResponse->json(),
                );
            }

            $this->waitForVideoAvailable($videoUrn, $headers);

            $postResponse = LinkedInHttpClient::api($headers)
                ->post('https://api.linkedin.com/rest/posts', [
                    'author' => $personUrn,
                    'commentary' => mb_substr($caption, 0, 3000),
                    'visibility' => 'PUBLIC',
                    'distribution' => [
                        'feedDistribution' => 'MAIN_FEED',
                        'targetEntities' => [],
                        'thirdPartyDistributionChannels' => [],
                    ],
                    'content' => [
                        'media' => [
                            'title' => mb_substr($video->title, 0, 200),
                            'id' => $videoUrn,
                        ],
                    ],
                    'lifecycleState' => 'PUBLISHED',
                    'isReshareDisabledByAuthor' => false,
                ]);

            if ($postResponse->failed()) {
                Log::warning('linkedin: create post failed', ['body' => $postResponse->body()]);

                return PublishResult::fail(
                    'LinkedIn posts: '.$postResponse->status().' — '.$postResponse->body(),
                    $postResponse->json(),
                );
            }

            $postId = $postResponse->header('x-restli-id') ?? $postResponse->header('X-RestLi-Id');
            $postId = is_string($postId) ? $postId : $videoUrn;

            return PublishResult::ok(
                $postId,
                $this->buildPostUrl($postId),
                [
                    'video_urn' => $videoUrn,
                    'post_id' => $postId,
                    'response' => $postResponse->json(),
                ],
            );
        } catch (RuntimeException $e) {
            return PublishResult::fail($e->getMessage());
        } catch (\Throwable $e) {
            return PublishResult::fail($e->getMessage());
        }
    }

    /**
     * @param  list<array{uploadUrl?: string, firstByte?: int, lastByte?: int}>  $instructions
     * @return list<string>
     */
    private function uploadVideoParts(string $videoPath, array $instructions): array
    {
        $uploadedPartIds = [];
        $handle = fopen($videoPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo de video.');
        }

        try {
            foreach ($instructions as $instruction) {
                $uploadUrl = $instruction['uploadUrl'] ?? null;
                $firstByte = (int) ($instruction['firstByte'] ?? 0);
                $lastByte = (int) ($instruction['lastByte'] ?? 0);
                $length = $lastByte - $firstByte + 1;

                if (! is_string($uploadUrl) || $uploadUrl === '' || $length <= 0) {
                    throw new RuntimeException('Instrucción de subida de LinkedIn inválida.');
                }

                fseek($handle, $firstByte);
                $chunk = fread($handle, $length);

                if ($chunk === false) {
                    throw new RuntimeException('No se pudo leer un fragmento del video.');
                }

                $response = LinkedInHttpClient::upload()
                    ->withHeaders([
                        'Content-Type' => 'application/octet-stream',
                    ])
                    ->withBody($chunk, 'application/octet-stream')
                    ->send('PUT', $uploadUrl);

                if ($response->failed()) {
                    throw new RuntimeException(
                        'Error subiendo fragmento a LinkedIn: '.$response->status().' — '.$response->body()
                    );
                }

                $etag = $response->header('etag') ?? $response->header('ETag');

                if (! is_string($etag) || $etag === '') {
                    throw new RuntimeException('LinkedIn no devolvió ETag del fragmento subido.');
                }

                $uploadedPartIds[] = trim($etag, '"');
            }
        } finally {
            fclose($handle);
        }

        return $uploadedPartIds;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function waitForVideoAvailable(string $videoUrn, array $headers): void
    {
        $encodedUrn = rawurlencode($videoUrn);

        for ($attempt = 0; $attempt < 45; $attempt++) {
            $response = LinkedInHttpClient::api($headers)
                ->get('https://api.linkedin.com/rest/videos/'.$encodedUrn);

            if ($response->successful()) {
                $status = (string) ($response->json('status') ?? '');

                if ($status === 'AVAILABLE') {
                    return;
                }

                if (in_array($status, ['PROCESSING_FAILED', 'CLIENT_ERROR'], true)) {
                    throw new RuntimeException('LinkedIn no procesó el video: '.$status);
                }
            }

            sleep(2);
        }

        throw new RuntimeException('LinkedIn tardó demasiado en procesar el video. Intenta de nuevo.');
    }

    private function buildPostUrl(string $postId): ?string
    {
        if ($postId === '') {
            return null;
        }

        return 'https://www.linkedin.com/feed/update/'.rawurlencode($postId);
    }
}
