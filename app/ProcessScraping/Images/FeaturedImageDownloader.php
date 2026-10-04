<?php

namespace App\ProcessScraping\Images;

use App\ProcessScraping\Support\FeaturedImageStorage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FeaturedImageDownloader
{
    /**
     * @return array{path: ?string, status: ?int, bytes: int, reason: ?string}
     */
    public function downloadToFeaturedPath(string $url, int $newsId, ?string $articleUrl = null): array
    {
        $referers = $this->referersFor($url, $articleUrl);
        $last = [
            'path' => null,
            'status' => null,
            'bytes' => 0,
            'reason' => 'max_retries',
        ];

        $acceptProfiles = $this->acceptProfilesFor($url);

        foreach ($acceptProfiles as $accept) {
            foreach ($referers as $referer) {
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    $last = $this->attemptDownload($url, $newsId, $referer, $accept);

                    if ($last['path'] !== null) {
                        return $last;
                    }

                    if ($attempt < 3) {
                        usleep(350_000 * $attempt);
                    }
                }
            }
        }

        return $last;
    }

    /**
     * @return array{path: ?string, status: ?int, bytes: int, reason: ?string}
     */
    private function attemptDownload(string $url, int $newsId, string $referer, string $accept): array
    {
        if (! FeaturedImageStorage::ensureWritable()) {
            return [
                'path' => null,
                'status' => null,
                'bytes' => 0,
                'reason' => 'storage_not_writable',
            ];
        }

        try {
            $response = Http::timeout(45)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Accept' => $accept,
                    'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.8',
                    'Referer' => $referer,
                ])
                ->get($url);

            $status = $response->status();
            $body = $response->body();
            $bytes = strlen($body);

            if ($response->failed() || $bytes < 512) {
                return [
                    'path' => null,
                    'status' => $status,
                    'bytes' => $bytes,
                    'reason' => $response->failed() ? 'http_error' : 'body_too_small',
                ];
            }

            if (! FeaturedImageValidator::isValidImageBytes($body)) {
                Log::info('featured_image: bytes no reconocidos como imagen', [
                    'news_id' => $newsId,
                    'url' => $url,
                    'bytes' => $bytes,
                    'magic' => bin2hex(substr($body, 0, 8)),
                    'content_type' => $response->header('Content-Type'),
                ]);

                return [
                    'path' => null,
                    'status' => $status,
                    'bytes' => $bytes,
                    'reason' => 'invalid_image_bytes',
                ];
            }

            $extension = FeaturedImageValidator::guessExtensionFromBytes($body)
                ?? FeaturedImageValidator::guessExtensionFromContentType($response->header('Content-Type'));

            $extension = $extension === 'jpeg' ? 'jpg' : $extension;

            $this->purgeExistingFeaturedFiles($newsId);

            $path = "featured-images/{$newsId}.{$extension}";

            Storage::disk('public')->put($path, $body);

            if (FeaturedImageValidator::isValidRelativePath($path)) {
                return [
                    'path' => $path,
                    'status' => $status,
                    'bytes' => $bytes,
                    'reason' => null,
                ];
            }

            Storage::disk('public')->delete($path);

            $saved = $this->saveBodyViaTempFile($body, $newsId, $extension);

            if ($saved !== null) {
                return [
                    'path' => $saved,
                    'status' => $status,
                    'bytes' => $bytes,
                    'reason' => null,
                ];
            }

            Log::warning('featured_image: imagen válida en memoria pero no persistible en disco', [
                'news_id' => $newsId,
                'url' => $url,
                'bytes' => $bytes,
                'extension' => $extension,
            ]);

            return [
                'path' => null,
                'status' => $status,
                'bytes' => $bytes,
                'reason' => 'invalid_image_bytes',
            ];
        } catch (\Throwable $e) {
            return [
                'path' => null,
                'status' => null,
                'bytes' => 0,
                'reason' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return list<string>
     */
    private function referersFor(string $url, ?string $articleUrl): array
    {
        $referers = [];

        if (str_contains($url, 'animenewsnetwork.com')) {
            $referers[] = 'https://www.animenewsnetwork.com/';
        }

        if ($articleUrl !== null && $articleUrl !== '') {
            $referers[] = $articleUrl;
        }

        $referers[] = 'https://www.animenewsnetwork.com/';

        return array_values(array_unique($referers));
    }

    /**
     * @return list<string>
     */
    private function acceptProfilesFor(string $url): array
    {
        $jpegFirst = 'image/jpeg,image/png,image/*;q=0.9,*/*;q=0.5';

        if (str_contains($url, 'animenewsnetwork.com')) {
            return [$jpegFirst];
        }

        return [
            $jpegFirst,
            'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        ];
    }

    private function purgeExistingFeaturedFiles(int $newsId): void
    {
        foreach (['webp', 'jpg', 'jpeg', 'png', 'gif', 'tmp'] as $extension) {
            $path = "featured-images/{$newsId}.{$extension}";

            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    private function saveBodyViaTempFile(string $body, int $newsId, string $extension): ?string
    {
        $disk = Storage::disk('public');
        $tmpRelative = "featured-images/{$newsId}.tmp";
        $finalRelative = "featured-images/{$newsId}.{$extension}";

        $disk->put($tmpRelative, $body);

        $tmpAbsolute = $disk->path($tmpRelative);

        if (! FeaturedImageValidator::isValidAbsolutePath($tmpAbsolute)) {
            $disk->delete($tmpRelative);

            return null;
        }

        if ($disk->exists($finalRelative)) {
            $disk->delete($finalRelative);
        }

        if (! @rename($tmpAbsolute, $disk->path($finalRelative))) {
            $disk->put($finalRelative, $body);
            $disk->delete($tmpRelative);
        }

        if (! FeaturedImageValidator::isValidRelativePath($finalRelative)) {
            $disk->delete($finalRelative);

            return null;
        }

        return $finalRelative;
    }
}
