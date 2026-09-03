<?php

namespace App\SocialPublishing\Platforms\Facebook;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Subida por fases (start → transfer → finish) en graph-video /{page-id}/videos.
 *
 * @see https://developers.facebook.com/docs/graph-api/reference/page/videos/
 */
class FacebookPageVideoChunkedUpload
{
    private const GRAPH_VERSION = 'v21.0';

    private const CHUNK_SIZE = 8 * 1024 * 1024;

    private readonly FacebookUploadRateLimiter $rateLimiter;

    public function __construct(?FacebookUploadRateLimiter $rateLimiter = null)
    {
        $this->rateLimiter = $rateLimiter ?? new FacebookUploadRateLimiter;
    }

    /**
     * @param  array<string, mixed>  $finishParams
     * @return array{video_id: string, response: mixed}
     */
    public function upload(
        string $pageId,
        string $accessToken,
        string $videoPath,
        array $finishParams,
        ?string $thumbnailPath = null,
    ): array {
        if (! is_file($videoPath)) {
            throw new RuntimeException('Archivo de video no encontrado.');
        }

        $fileSize = filesize($videoPath);

        if ($fileSize === false || $fileSize <= 0) {
            throw new RuntimeException('No se pudo leer el tamaño del video.');
        }

        $start = $this->rateLimiter->send(
            fn () => Http::timeout(120)->post($this->graphVideoUrl("/{$pageId}/videos"), [
                'access_token' => $accessToken,
                'upload_phase' => 'start',
                'file_size' => $fileSize,
            ]),
            'iniciar subida de video de página',
        );

        $sessionId = (string) ($start->json('upload_session_id') ?? '');
        $videoId = (string) ($start->json('video_id') ?? '');

        if ($sessionId === '') {
            throw new RuntimeException('Facebook no devolvió upload_session_id.');
        }

        Log::info('facebook: page video chunked upload start', [
            'page_id' => $pageId,
            'file_size' => $fileSize,
            'upload_session_id' => $sessionId,
            'video_id' => $videoId !== '' ? $videoId : null,
        ]);

        $this->transferChunks($pageId, $accessToken, $videoPath, $fileSize, $sessionId);

        $finish = $this->rateLimiter->send(
            fn () => $this->postFinish($pageId, $accessToken, $sessionId, $finishParams, $thumbnailPath),
            'finalizar subida de video de página',
        );

        $finishedVideoId = (string) ($finish->json('id') ?? $finish->json('video_id') ?? $videoId);

        if ($finishedVideoId === '') {
            throw new RuntimeException('Facebook no devolvió ID del video de página.');
        }

        Log::info('facebook: page video chunked upload complete', [
            'video_id' => $finishedVideoId,
            'file_size' => $fileSize,
        ]);

        return [
            'video_id' => $finishedVideoId,
            'response' => $finish->json(),
        ];
    }

    private function transferChunks(
        string $pageId,
        string $accessToken,
        string $videoPath,
        int $fileSize,
        string $sessionId,
    ): void {
        $file = fopen($videoPath, 'rb');

        if ($file === false) {
            throw new RuntimeException('No se pudo abrir el archivo de video.');
        }

        $offset = 0;

        try {
            while ($offset < $fileSize) {
                if (fseek($file, $offset) !== 0) {
                    throw new RuntimeException("No se pudo posicionar el archivo en offset {$offset}.");
                }

                $readSize = (int) min(self::CHUNK_SIZE, $fileSize - $offset);
                $chunk = fread($file, $readSize);

                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException("No se pudo leer el fragmento en offset {$offset}.");
                }

                $response = $this->rateLimiter->send(
                    fn () => Http::timeout(600)
                        ->attach('video_file_chunk', $chunk, 'chunk.mp4', ['Content-Type' => 'application/octet-stream'])
                        ->post($this->graphVideoUrl("/{$pageId}/videos"), [
                            'access_token' => $accessToken,
                            'upload_phase' => 'transfer',
                            'upload_session_id' => $sessionId,
                            'start_offset' => $offset,
                        ]),
                    'transferir fragmento de video de página',
                    $offset,
                );

                $nextOffset = $response->json('start_offset');

                if (is_numeric($nextOffset)) {
                    $offset = (int) $nextOffset;
                } else {
                    $offset += strlen($chunk);
                }

                Log::info('facebook: page video chunked transfer', [
                    'offset' => $offset,
                    'file_size' => $fileSize,
                ]);
            }
        } finally {
            fclose($file);
        }

        if ($offset < $fileSize) {
            throw new RuntimeException(
                "Subida de video de página incompleta ({$offset}/{$fileSize} bytes).",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $finishParams
     */
    private function postFinish(
        string $pageId,
        string $accessToken,
        string $sessionId,
        array $finishParams,
        ?string $thumbnailPath,
    ): \Illuminate\Http\Client\Response {
        $request = Http::timeout(120);

        if ($thumbnailPath !== null && is_file($thumbnailPath)) {
            $thumbMime = mime_content_type($thumbnailPath) ?: 'image/jpeg';
            $request = $request->attach(
                'thumb',
                fopen($thumbnailPath, 'r'),
                basename($thumbnailPath),
                ['Content-Type' => $thumbMime],
            );
        }

        return $request->post($this->graphVideoUrl("/{$pageId}/videos"), array_merge([
            'access_token' => $accessToken,
            'upload_phase' => 'finish',
            'upload_session_id' => $sessionId,
        ], $finishParams));
    }

    private function graphVideoUrl(string $path): string
    {
        return 'https://graph-video.facebook.com/'.self::GRAPH_VERSION.$path;
    }
}
