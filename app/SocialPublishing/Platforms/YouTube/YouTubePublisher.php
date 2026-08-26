<?php

namespace App\SocialPublishing\Platforms\YouTube;

use App\Models\SocialPlatformAccount;
use App\Models\SocialPublication;
use App\SocialPublishing\Contracts\SocialPublisherInterface;
use App\SocialPublishing\DTO\PublishResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class YouTubePublisher implements SocialPublisherInterface
{
    public function platform(): string
    {
        return 'youtube';
    }

    public function isConfigured(): bool
    {
        return config('social.youtube.client_id')
            && config('social.youtube.client_secret')
            && SocialPlatformAccount::youtubeRefreshToken() !== null;
    }

    public function publish(SocialPublication $publication): PublishResult
    {
        if (! $this->isConfigured()) {
            return PublishResult::fail(
                'YouTube no está conectado. Ve a Configuración → Conectar YouTube.'
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
            $accessToken = $this->accessToken();

            $metadata = [
                'snippet' => [
                    'title' => mb_substr($video->title, 0, 100),
                    'description' => $caption,
                    'categoryId' => '24',
                ],
                'status' => $this->buildVideoStatus($publication),
            ];

            $response = Http::withToken($accessToken)
                ->attach('metadata', json_encode($metadata), 'metadata', ['Content-Type' => 'application/json; charset=UTF-8'])
                ->attach('media', fopen($videoPath, 'r'), basename($videoPath), ['Content-Type' => 'video/*'])
                ->post('https://www.googleapis.com/upload/youtube/v3/videos?part=snippet,status&uploadType=multipart');

            if ($response->failed()) {
                Log::warning('youtube: upload failed', ['body' => $response->body()]);

                return PublishResult::fail(
                    'YouTube API: '.$response->status().' — '.$response->body(),
                    $response->json(),
                );
            }

            $data = $response->json();
            $videoId = $data['id'] ?? null;

            if ($videoId === null) {
                return PublishResult::fail('YouTube no devolvió ID de video.', $data);
            }

            $thumbnailResult = $this->uploadThumbnail($accessToken, $videoId, $video->thumbnail_path);

            if (! $thumbnailResult['ok']) {
                Log::warning('youtube: thumbnail upload failed', [
                    'video_id' => $videoId,
                    'error' => $thumbnailResult['error'] ?? 'unknown',
                ]);
                $data['thumbnail_upload'] = $thumbnailResult;
            } else {
                $data['thumbnail_upload'] = ['ok' => true];
            }

            return PublishResult::ok(
                $videoId,
                'https://www.youtube.com/watch?v='.$videoId,
                $data,
            );
        } catch (\Throwable $e) {
            return PublishResult::fail($e->getMessage());
        }
    }

    private function accessToken(): string
    {
        $refreshToken = SocialPlatformAccount::youtubeRefreshToken();

        if ($refreshToken === null) {
            throw new \RuntimeException('YouTube no conectado. Ve a Configuración y conecta tu canal.');
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('social.youtube.client_id'),
            'client_secret' => config('social.youtube.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('No se pudo refrescar token de YouTube: '.$response->body());
        }

        return (string) $response->json('access_token');
    }

    /**
     * @return array{privacyStatus: string, selfDeclaredMadeForKids: bool, publishAt?: string}
     */
    private function buildVideoStatus(SocialPublication $publication): array
    {
        $status = [
            'privacyStatus' => 'public',
            'selfDeclaredMadeForKids' => false,
        ];

        $scheduledAt = $publication->scheduled_at;

        if ($scheduledAt !== null && $scheduledAt->isFuture()) {
            $status['privacyStatus'] = 'private';
            $status['publishAt'] = $scheduledAt->utc()->format('Y-m-d\TH:i:s\Z');
        }

        return $status;
    }

    /**
     * @return array{ok: bool, error?: string, response?: mixed}
     */
    private function uploadThumbnail(string $accessToken, string $videoId, string $thumbnailPath): array
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($thumbnailPath)) {
            return ['ok' => false, 'error' => 'Archivo de miniatura no encontrado.'];
        }

        $sourcePath = $disk->path($thumbnailPath);
        $uploadPath = $sourcePath;
        $tempPath = null;

        try {
            if (filesize($sourcePath) > 2 * 1024 * 1024) {
                $tempPath = $this->compressThumbnailForYoutube($sourcePath);
                if ($tempPath === null) {
                    return ['ok' => false, 'error' => 'La miniatura supera 2 MB y no se pudo comprimir para YouTube.'];
                }
                $uploadPath = $tempPath;
            }

            $mime = mime_content_type($uploadPath) ?: 'image/jpeg';
            if (! in_array($mime, ['image/jpeg', 'image/png', 'image/jpg'], true)) {
                $mime = 'image/jpeg';
            }

            $response = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => $mime])
                ->withBody(fopen($uploadPath, 'r'), $mime)
                ->post('https://www.googleapis.com/upload/youtube/v3/thumbnails/set?videoId='.$videoId);

            if ($response->failed()) {
                $message = 'YouTube thumbnails.set: '.$response->status().' — '.$response->body();

                if ($response->status() === 403) {
                    $message .= ' (¿Canal verificado con permiso de miniaturas personalizadas?)';
                }

                return ['ok' => false, 'error' => $message];
            }

            return ['ok' => true, 'response' => $response->json()];
        } finally {
            if ($tempPath !== null && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private function compressThumbnailForYoutube(string $sourcePath): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $contents = file_get_contents($sourcePath);
        if ($contents === false) {
            return null;
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $maxSide = 1280;

        if ($width > $maxSide || $height > $maxSide) {
            $ratio = min($maxSide / $width, $maxSide / $height);
            $newWidth = (int) round($width * $ratio);
            $newHeight = (int) round($height * $ratio);
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'yt-thumb-');
        if ($tempPath === false) {
            imagedestroy($image);

            return null;
        }

        $tempPath .= '.jpg';

        for ($quality = 90; $quality >= 40; $quality -= 10) {
            imagejpeg($image, $tempPath, $quality);
            if (filesize($tempPath) <= 2 * 1024 * 1024) {
                imagedestroy($image);

                return $tempPath;
            }
        }

        imagedestroy($image);

        return is_file($tempPath) && filesize($tempPath) <= 2 * 1024 * 1024 ? $tempPath : null;
    }
}
