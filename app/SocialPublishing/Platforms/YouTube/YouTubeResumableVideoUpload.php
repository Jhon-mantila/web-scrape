<?php

namespace App\SocialPublishing\Platforms\YouTube;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Subida resumable (por fragmentos) recomendada por Google para videos grandes.
 *
 * @see https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol
 */
class YouTubeResumableVideoUpload
{
    private const CHUNK_SIZE = 8 * 1024 * 1024;

    private const CHUNK_TIMEOUT_SECONDS = 600;

    /**
     * @param  array{snippet: array<string, mixed>, status: array<string, mixed>}  $metadata
     * @return array<string, mixed>
     */
    public function upload(string $accessToken, array $metadata, string $videoPath): array
    {
        if (! is_file($videoPath)) {
            throw new RuntimeException('Archivo de video no encontrado.');
        }

        $fileSize = filesize($videoPath);

        if ($fileSize === false || $fileSize <= 0) {
            throw new RuntimeException('No se pudo leer el tamaño del video.');
        }

        $init = Http::withToken($accessToken)
            ->timeout(120)
            ->withHeaders([
                'Content-Type' => 'application/json; charset=UTF-8',
                'X-Upload-Content-Length' => (string) $fileSize,
                'X-Upload-Content-Type' => 'video/*',
            ])
            ->post(
                'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status',
                $metadata,
            );

        if ($init->failed()) {
            throw new RuntimeException(
                'YouTube no inició la subida resumable: '.$init->status().' — '.$init->body(),
            );
        }

        $location = $init->header('Location') ?? $init->header('location');

        if (! is_string($location) || trim($location) === '') {
            throw new RuntimeException('YouTube no devolvió URL de subida resumable (Location).');
        }

        Log::info('youtube: resumable upload start', [
            'file_size' => $fileSize,
            'chunk_size' => self::CHUNK_SIZE,
        ]);

        $handle = fopen($videoPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo de video.');
        }

        try {
            $offset = 0;

            while ($offset < $fileSize) {
                $readSize = (int) min(self::CHUNK_SIZE, $fileSize - $offset);
                $chunk = fread($handle, $readSize);

                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Error leyendo fragmento del video en disco.');
                }

                $chunkLen = strlen($chunk);
                $end = $offset + $chunkLen - 1;

                $response = Http::withToken($accessToken)
                    ->timeout(self::CHUNK_TIMEOUT_SECONDS)
                    ->withHeaders([
                        'Content-Length' => (string) $chunkLen,
                        'Content-Range' => "bytes {$offset}-{$end}/{$fileSize}",
                    ])
                    ->withBody($chunk, 'video/*')
                    ->put($location);

                if ($response->status() === 308) {
                    $offset = $this->nextOffsetFromIncomplete($response, $end + 1);
                    fseek($handle, $offset);

                    continue;
                }

                if ($response->successful()) {
                    /** @var array<string, mixed> $data */
                    $data = $response->json();

                    Log::info('youtube: resumable upload complete', [
                        'file_size' => $fileSize,
                        'video_id' => $data['id'] ?? null,
                    ]);

                    return $data;
                }

                throw new RuntimeException(
                    'YouTube rechazó un fragmento: '.$response->status().' — '.$response->body(),
                );
            }
        } finally {
            fclose($handle);
        }

        throw new RuntimeException('YouTube no confirmó la subida del video.');
    }

    private function nextOffsetFromIncomplete(Response $response, int $fallback): int
    {
        $range = $response->header('Range') ?? $response->header('range');

        if (is_string($range) && preg_match('/bytes=0-(\d+)/', $range, $matches) === 1) {
            return ((int) $matches[1]) + 1;
        }

        return $fallback;
    }
}
