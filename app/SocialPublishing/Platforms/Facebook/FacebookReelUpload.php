<?php

namespace App\SocialPublishing\Platforms\Facebook;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Subida de Reels: /{page-id}/video_reels + rupload.facebook.com.
 *
 * @see https://developers.facebook.com/docs/video-api/guides/reels-publishing/
 */
class FacebookReelUpload
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
    ): array {
        if (! is_file($videoPath)) {
            throw new RuntimeException('Archivo de video no encontrado.');
        }

        $fileSize = filesize($videoPath);

        if ($fileSize === false || $fileSize <= 0) {
            throw new RuntimeException('No se pudo leer el tamaño del video.');
        }

        $start = $this->rateLimiter->send(
            fn () => Http::timeout(120)->post($this->graphUrl("/{$pageId}/video_reels"), [
                'access_token' => $accessToken,
                'upload_phase' => 'start',
            ]),
            'iniciar subida de Reel',
        );

        $videoId = (string) ($start->json('video_id') ?? '');

        if ($videoId === '') {
            throw new RuntimeException('Facebook no devolvió video_id del Reel.');
        }

        Log::info('facebook: reel upload start', [
            'page_id' => $pageId,
            'file_size' => $fileSize,
            'video_id' => $videoId,
        ]);

        $this->transferChunks($videoId, $accessToken, $videoPath, $fileSize);

        $finish = $this->rateLimiter->send(
            fn () => Http::timeout(120)->post($this->graphUrl("/{$pageId}/video_reels"), array_merge([
                'access_token' => $accessToken,
                'upload_phase' => 'finish',
                'video_id' => $videoId,
            ], $finishParams)),
            'finalizar subida de Reel',
        );

        $finishedVideoId = (string) ($finish->json('id') ?? $finish->json('video_id') ?? $videoId);

        if ($finishedVideoId === '') {
            throw new RuntimeException('Facebook no devolvió ID del Reel.');
        }

        Log::info('facebook: reel upload complete', [
            'video_id' => $finishedVideoId,
            'file_size' => $fileSize,
        ]);

        return [
            'video_id' => $finishedVideoId,
            'response' => $finish->json(),
        ];
    }

    private function transferChunks(
        string $videoId,
        string $accessToken,
        string $videoPath,
        int $fileSize,
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

                $this->rateLimiter->send(
                    fn () => Http::timeout(600)
                        ->withHeaders([
                            'Authorization' => 'OAuth '.$accessToken,
                            'offset' => (string) $offset,
                            'file_size' => (string) $fileSize,
                        ])
                        ->withBody($chunk, 'application/octet-stream')
                        ->post($this->ruploadUrl($videoId)),
                    'transferir fragmento de Reel',
                    $offset,
                );

                $offset += strlen($chunk);

                Log::info('facebook: reel upload chunk', [
                    'video_id' => $videoId,
                    'offset' => $offset,
                    'file_size' => $fileSize,
                ]);
            }
        } finally {
            fclose($file);
        }

        if ($offset !== $fileSize) {
            throw new RuntimeException(
                "Subida de Reel incompleta ({$offset}/{$fileSize} bytes).",
            );
        }
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.self::GRAPH_VERSION.$path;
    }

    private function ruploadUrl(string $videoId): string
    {
        return 'https://rupload.facebook.com/video-upload/'.self::GRAPH_VERSION.'/'.$videoId;
    }
}
