<?php

namespace App\SocialPublishing\Platforms\Facebook;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Resumable Upload API + publicación en /{page-id}/videos (video de página, no Reel).
 *
 * @see https://developers.facebook.com/docs/video-api/guides/publishing/
 */
class FacebookResumableVideoUpload
{
    private const GRAPH_VERSION = 'v21.0';

    /** Fragmentos más grandes = menos peticiones (límite Meta ~10 rps en upload). */
    private const CHUNK_SIZE = 8 * 1024 * 1024;

    /** Espacio mínimo entre peticiones de fragmento (~4 rps). */
    private const MIN_REQUEST_INTERVAL_US = 250_000;

    private const MAX_RATE_LIMIT_RETRIES = 12;

    private float $lastChunkRequestAt = 0.0;

    /**
     * @return array{handle: string, session_id: string}
     */
    public function upload(string $appId, string $accessToken, string $videoPath): array
    {
        if (! is_file($videoPath)) {
            throw new RuntimeException('Archivo de video no encontrado.');
        }

        $fileSize = filesize($videoPath);

        if ($fileSize === false || $fileSize <= 0) {
            throw new RuntimeException('No se pudo leer el tamaño del video.');
        }

        $fileName = basename($videoPath);
        $sessionId = $this->startSession($appId, $accessToken, $fileName, $fileSize);
        $handle = $this->transferFileInChunks($sessionId, $accessToken, $videoPath, $fileSize);

        return [
            'handle' => $handle,
            'session_id' => $sessionId,
        ];
    }

    private function startSession(string $appId, string $accessToken, string $fileName, int $fileSize): string
    {
        $response = Http::timeout(120)->post($this->graphUrl("/{$appId}/uploads"), [
            'file_name' => $fileName,
            'file_length' => $fileSize,
            'file_type' => 'video/mp4',
            'access_token' => $accessToken,
        ]);

        FacebookGraphResponse::assertSuccessful($response, 'iniciar subida reanudable');

        $sessionId = (string) ($response->json('id') ?? '');

        if ($sessionId === '' || ! str_starts_with($sessionId, 'upload:')) {
            throw new RuntimeException('Facebook no devolvió sesión de subida (upload:…).');
        }

        Log::info('facebook: resumable upload start', [
            'app_id' => $appId,
            'file_size' => $fileSize,
            'session_id' => $sessionId,
        ]);

        return $sessionId;
    }

    private function transferFileInChunks(
        string $sessionId,
        string $accessToken,
        string $videoPath,
        int $fileSize,
    ): string {
        $handle = '';
        $offset = 0;
        $file = fopen($videoPath, 'rb');

        if ($file === false) {
            throw new RuntimeException('No se pudo abrir el archivo de video.');
        }

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

                $response = $this->postChunkWithRetry(
                    $sessionId,
                    $accessToken,
                    $offset,
                    $chunk,
                );

                $chunkHandle = $this->extractHandle($response);

                if ($this->isValidHandle($chunkHandle)) {
                    $handle = $chunkHandle;
                }

                $nextOffset = $response->json('file_offset');

                if (is_numeric($nextOffset)) {
                    $offset = (int) $nextOffset;
                } else {
                    $offset += strlen($chunk);
                }

                Log::info('facebook: resumable upload chunk', [
                    'session_id' => $sessionId,
                    'offset' => $offset,
                    'file_size' => $fileSize,
                    'handle_prefix' => $this->isValidHandle($handle) ? mb_substr($handle, 0, 24).'…' : null,
                ]);
            }
        } finally {
            fclose($file);
        }

        if ($offset !== $fileSize) {
            throw new RuntimeException(
                "Subida reanudable incompleta a Facebook ({$offset}/{$fileSize} bytes).",
            );
        }

        if ($handle === '') {
            throw new RuntimeException('Facebook no devolvió handle (h) del video subido.');
        }

        Log::info('facebook: resumable upload transfer complete', [
            'session_id' => $sessionId,
            'file_size' => $fileSize,
            'handle_prefix' => mb_substr($handle, 0, 24).'…',
        ]);

        return $handle;
    }

    private function postChunkWithRetry(
        string $sessionId,
        string $accessToken,
        int $offset,
        string $chunk,
    ): \Illuminate\Http\Client\Response {
        $rateLimitAttempts = 0;

        while (true) {
            $this->waitForChunkSlot();

            $response = Http::timeout(600)
                ->withHeaders([
                    'Authorization' => 'OAuth '.$accessToken,
                    'file_offset' => (string) $offset,
                ])
                ->withBody($chunk, 'application/octet-stream')
                ->post($this->graphUrl('/'.$sessionId));

            if ($response->status() === 429 || $this->isUploadRateLimited($response)) {
                $rateLimitAttempts++;

                if ($rateLimitAttempts > self::MAX_RATE_LIMIT_RETRIES) {
                    throw new RuntimeException(
                        "Facebook (transferir fragmento en offset {$offset}): límite de subida superado tras {$rateLimitAttempts} reintentos.",
                    );
                }

                $backoffMs = $this->parseBackoffMs($response);
                $waitSeconds = (int) ceil($backoffMs / 1000);

                Log::warning('facebook: resumable upload rate limited', [
                    'offset' => $offset,
                    'attempt' => $rateLimitAttempts,
                    'backoff_ms' => $backoffMs,
                    'status' => $response->status(),
                ]);

                sleep(max(1, $waitSeconds));

                continue;
            }

            FacebookGraphResponse::assertSuccessful($response, "transferir fragmento en offset {$offset}");

            return $response;
        }
    }

    private function waitForChunkSlot(): void
    {
        if ($this->lastChunkRequestAt <= 0) {
            $this->lastChunkRequestAt = microtime(true);

            return;
        }

        $elapsedUs = (int) ((microtime(true) - $this->lastChunkRequestAt) * 1_000_000);

        if ($elapsedUs < self::MIN_REQUEST_INTERVAL_US) {
            usleep(self::MIN_REQUEST_INTERVAL_US - $elapsedUs);
        }

        $this->lastChunkRequestAt = microtime(true);
    }

    private function isUploadRateLimited(\Illuminate\Http\Client\Response $response): bool
    {
        $type = $response->json('debug_info.type');

        return is_string($type) && $type === 'UploadRateLimitedError';
    }

    private function parseBackoffMs(\Illuminate\Http\Client\Response $response): int
    {
        $backoff = $response->json('backoff');

        if (is_numeric($backoff) && (int) $backoff > 0) {
            return (int) $backoff;
        }

        return 60_000;
    }

    private function isValidHandle(string $handle): bool
    {
        return $handle !== '' && preg_match('/^\d+:/', $handle) === 1;
    }

    private function extractHandle(\Illuminate\Http\Client\Response $response): string
    {
        $handle = $response->json('h');

        if (is_string($handle) && $handle !== '') {
            $normalized = $this->normalizeHandle($handle);

            return $this->isValidHandle($normalized) ? $normalized : '';
        }

        $body = trim($response->body());

        if ($body === '') {
            return '';
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded) && isset($decoded['h']) && is_string($decoded['h'])) {
            $normalized = $this->normalizeHandle($decoded['h']);

            return $this->isValidHandle($normalized) ? $normalized : '';
        }

        // Respuesta con varios handles en líneas: usar el último válido.
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $body))));

        foreach (array_reverse($lines) as $line) {
            $lineDecoded = json_decode($line, true);

            if (is_array($lineDecoded) && isset($lineDecoded['h']) && is_string($lineDecoded['h'])) {
                $normalized = $this->normalizeHandle($lineDecoded['h']);

                if ($this->isValidHandle($normalized)) {
                    return $normalized;
                }
            }

            $normalized = $this->normalizeHandle($line);

            if ($this->isValidHandle($normalized)) {
                return $normalized;
            }
        }

        return '';
    }

    private function normalizeHandle(string $handle): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $handle))));

        if ($lines === []) {
            return trim($handle);
        }

        foreach (array_reverse($lines) as $line) {
            if ($this->isValidHandle($line)) {
                return $line;
            }
        }

        return (string) end($lines);
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.self::GRAPH_VERSION.$path;
    }
}
