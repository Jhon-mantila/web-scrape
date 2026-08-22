<?php

namespace App\ProcessScraping\Actions;

use App\Models\News;
use App\ProcessScraping\Images\FeaturedImageExtractor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DownloadFeaturedImagesAction
{
    public function __construct(
        private readonly FeaturedImageExtractor $extractor,
    ) {}

    /**
     * @return array{processed: int, success: int, skipped: int, failed: int}
     */
    public function execute(int $limit): array
    {
        $processed = 0;
        $success = 0;
        $skipped = 0;
        $failed = 0;

        $items = News::query()
            ->whereHas('detail', function ($q) {
                $q->where('status', 'processed')
                    ->whereNull('featured_image_path');
            })
            ->with('detail')
            ->orderBy('id')
            ->limit(max($limit, 1))
            ->get();

        foreach ($items as $news) {
            $processed++;
            $result = $this->downloadForNews($news);

            match ($result) {
                'success' => $success++,
                'skipped' => $skipped++,
                default => $failed++,
            };
        }

        return compact('processed', 'success', 'skipped', 'failed');
    }

    public function downloadForNews(News $news): string
    {
        $news->loadMissing('detail');

        if ($news->detail?->featured_image_path) {
            return 'success';
        }

        try {
            $imageUrl = $this->extractor->extract($news);

            if ($imageUrl === null) {
                return 'skipped';
            }

            $path = $this->download($imageUrl, $news->id);

            if ($path === null) {
                return 'failed';
            }

            $news->detail?->update(['featured_image_path' => $path]);

            return 'success';
        } catch (Throwable $e) {
            Log::warning('featured_image: fallo al descargar', [
                'news_id' => $news->id,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    private function download(string $url, int $newsId): ?string
    {
        $response = Http::timeout(30)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; AnimeScraper/1.0)'])
            ->get($url);

        if ($response->failed()) {
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
