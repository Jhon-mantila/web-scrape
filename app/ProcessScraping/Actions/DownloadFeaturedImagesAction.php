<?php

namespace App\ProcessScraping\Actions;

use App\Models\News;
use App\ProcessScraping\Images\FeaturedImageExtractor;
use App\ProcessScraping\Images\FeaturedImageWatermarker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DownloadFeaturedImagesAction
{
    public function __construct(
        private readonly FeaturedImageExtractor $extractor,
        private readonly GenerateFeaturedImagesAction $generateImages,
        private readonly FeaturedImageWatermarker $watermarker,
    ) {}

    /**
     * @return array{
     *     processed: int,
     *     downloaded: int,
     *     generated: int,
     *     success: int,
     *     skipped: int,
     *     failed: int
     * }
     */
    public function execute(int $limit, bool $skipGenerate = false): array
    {
        $processed = 0;
        $downloaded = 0;
        $generated = 0;
        $skipped = 0;
        $failed = 0;

        $items = News::query()
            ->whereHas('detail', fn ($q) => $q->where('status', 'processed'))
            ->with('detail')
            ->orderBy('id')
            ->get()
            ->filter(fn (News $news) => $this->needsFeaturedImageDownload($news))
            ->take(max($limit, 1))
            ->values();

        foreach ($items as $news) {
            $processed++;

            $result = $this->processForNews($news, $skipGenerate);

            match ($result) {
                'downloaded' => $downloaded++,
                'generated' => $generated++,
                'skipped' => $skipped++,
                default => $failed++,
            };
        }

        return [
            'processed' => $processed,
            'downloaded' => $downloaded,
            'generated' => $generated,
            'success' => $downloaded + $generated,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    private function processForNews(News $news, bool $skipGenerate): string
    {
        $news->loadMissing('detail');

        if ($this->featuredImageExists($news)) {
            return 'downloaded';
        }

        $scrapeResult = $this->downloadForNews($news);

        if ($scrapeResult === 'success') {
            return 'downloaded';
        }

        if ($skipGenerate || ! config('services.comfyui.enabled')) {
            return match ($scrapeResult) {
                'skipped' => 'skipped',
                default => 'failed',
            };
        }

        return match ($this->generateImages->generateForNews($news)) {
            'generated' => 'generated',
            'skipped' => 'skipped',
            default => 'failed',
        };
    }

    public function downloadForNews(News $news): string
    {
        $news->loadMissing('detail');

        if ($this->featuredImageExists($news)) {
            return 'success';
        }

        try {
            $candidates = $this->extractor->collectCandidates($news);

            if ($candidates === []) {
                Log::info('featured_image: sin candidatos de imagen', ['news_id' => $news->id]);

                return 'skipped';
            }

            $path = null;

            foreach ($candidates as $imageUrl) {
                $path = $this->download($imageUrl, $news->id);

                if ($path !== null) {
                    break;
                }

                Log::info('featured_image: candidato no descargable', [
                    'news_id' => $news->id,
                    'url' => $imageUrl,
                ]);
            }

            if ($path === null) {
                return 'failed';
            }

            $finalPath = $this->persistFeaturedImagePath($news, $path);

            if ($finalPath === null) {
                return 'failed';
            }

            return 'success';
        } catch (Throwable $e) {
            Log::warning('featured_image: fallo al descargar', [
                'news_id' => $news->id,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    private function persistFeaturedImagePath(News $news, string $path): ?string
    {
        $finalPath = $this->watermarker->apply($path);

        if ($finalPath === null) {
            Log::warning('featured_image: no se aplicó webp/marca de agua (no se guarda JPG crudo)', [
                'news_id' => $news->id,
                'path' => $path,
                'watermark_enabled' => config('services.featured_image.watermark_enabled'),
            ]);

            return null;
        }

        if (! Storage::disk('public')->exists($finalPath)) {
            Log::warning('featured_image: archivo no encontrado tras procesar', [
                'news_id' => $news->id,
                'path' => $finalPath,
            ]);

            return null;
        }

        $news->detail?->update([
            'featured_image_path' => $finalPath,
            'featured_image_source' => 'scraped',
        ]);

        return $finalPath;
    }

    public function needsFeaturedImageDownload(News $news): bool
    {
        $news->loadMissing('detail');

        if ($news->detail?->status !== 'processed') {
            return false;
        }

        return ! $this->featuredImageExists($news);
    }

    public function featuredImageExists(News $news): bool
    {
        $news->loadMissing('detail');

        return $this->resolveExistingStoragePath($news->detail?->featured_image_path) !== null;
    }

    public function resolveExistingStoragePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (Storage::disk('public')->exists($path)) {
            return $path;
        }

        $info = pathinfo($path);
        $filename = $info['filename'] ?? '';

        if ($filename === '') {
            return null;
        }

        $dir = $info['dirname'] ?? '';
        $prefix = $dir !== '' && $dir !== '.' ? $dir.'/' : '';

        foreach (['webp', 'jpg', 'jpeg', 'png'] as $extension) {
            $candidate = $prefix.$filename.'.'.$extension;

            if (Storage::disk('public')->exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function download(string $url, int $newsId): ?string
    {
        $response = Http::timeout(30)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; AnimeScraper/1.0)',
                'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            ])
            ->get($url);

        if ($response->failed() || strlen($response->body()) < 512) {
            return null;
        }

        $contentType = $response->header('Content-Type') ?? 'image/jpeg';
        $extension = match (true) {
            str_contains($contentType, 'png') => 'png',
            str_contains($contentType, 'webp') => 'webp',
            str_contains($contentType, 'gif') => 'gif',
            default => 'jpg',
        };

        $path = "featured-images/{$newsId}.{$extension}";

        Storage::disk('public')->put($path, $response->body());

        return $path;
    }
}
