<?php

namespace App\ProcessScraping\Actions;

use App\Models\News;
use App\ProcessScraping\Images\FeaturedImageDownloader;
use App\ProcessScraping\Images\FeaturedImageExtractor;
use App\ProcessScraping\Images\FeaturedImageWatermarker;
use App\ProcessScraping\Images\FeaturedImageValidator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DownloadFeaturedImagesAction
{
    public function __construct(
        private readonly FeaturedImageExtractor $extractor,
        private readonly FeaturedImageDownloader $imageDownloader,
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
    /**
     * @param  list<int>|null  $newsIds  Lote del pipeline (mismo criterio que IA/WP). Si null, FIFO global.
     */
    public function execute(int $limit, bool $skipGenerate = false, ?array $newsIds = null): array
    {
        $processed = 0;
        $downloaded = 0;
        $generated = 0;
        $skipped = 0;
        $failed = 0;

        $items = $this->resolveNewsForImageStep($limit, $newsIds);

        foreach ($items as $news) {
            $processed++;

            if ($processed > 1) {
                usleep(400_000);
            }

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

        $this->purgeInvalidFeaturedImageFiles($news->id);

        if ($this->featuredImageExists($news)) {
            $this->syncFeaturedImagePathFromDisk($news);

            return 'success';
        }

        try {
            $candidates = $this->extractor->collectCandidates($news);

            if ($candidates === []) {
                $candidates = $this->extractor->collectCandidatesFromLivePage($news);
            }

            if ($candidates === []) {
                Log::info('featured_image: sin candidatos de imagen', ['news_id' => $news->id]);

                return 'skipped';
            }

            $path = $this->downloadFirstWorkingCandidate($news, $candidates);

            if ($path === null) {
                $extra = $this->extractor->collectCandidatesFromLivePage($news);

                if ($extra !== []) {
                    $path = $this->downloadFirstWorkingCandidate($news, $extra);
                }
            }

            if ($path === null) {
                return 'failed';
            }

            $finalPath = $this->persistFeaturedImagePath($news, $path);

            if ($finalPath === null) {
                return 'failed';
            }

            Log::info('featured_image: guardada', [
                'news_id' => $news->id,
                'path' => $finalPath,
            ]);

            return 'success';
        } catch (Throwable $e) {
            Log::warning('featured_image: fallo al descargar', [
                'news_id' => $news->id,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /**
     * @param  list<int>|null  $newsIds
     * @return \Illuminate\Support\Collection<int, News>
     */
    private function resolveNewsForImageStep(int $limit, ?array $newsIds)
    {
        $cap = max($limit, 1);

        if ($newsIds !== null && $newsIds !== []) {
            return News::query()
                ->whereIn('id', $newsIds)
                ->whereHas('detail', fn ($q) => $q->where('status', 'processed'))
                ->with('detail')
                ->orderBy('id')
                ->get()
                ->filter(function (News $news) {
                    $this->purgeInvalidFeaturedImageFiles($news->id);

                    return ! $this->featuredImageExists($news);
                })
                ->values();
        }

        return News::query()
            ->whereHas('detail', fn ($q) => $q->where('status', 'processed'))
            ->with('detail')
            ->orderBy('id')
            ->get()
            ->filter(fn (News $news) => $this->needsFeaturedImageDownload($news))
            ->take($cap)
            ->values();
    }

    private function persistFeaturedImagePath(News $news, string $path): ?string
    {
        if (! FeaturedImageValidator::isValidRelativePath($path)) {
            Storage::disk('public')->delete($path);
            Log::warning('featured_image: archivo descargado no es una imagen válida', [
                'news_id' => $news->id,
                'path' => $path,
            ]);

            return null;
        }

        $news->detail?->update([
            'featured_image_path' => $path,
            'featured_image_source' => 'scraped',
        ]);

        $finalPath = null;

        if (FeaturedImageWatermarker::canProcess()) {
            $finalPath = $this->watermarker->apply($path);
        }

        if ($finalPath === null || ! FeaturedImageValidator::isValidRelativePath($finalPath)) {
            if (FeaturedImageValidator::isValidRelativePath($path)) {
                Log::warning('featured_image: marca de agua/webp omitida; se usa archivo descargado', [
                    'news_id' => $news->id,
                    'path' => $path,
                    'watermark_enabled' => config('services.featured_image.watermark_enabled'),
                    'gd_available' => FeaturedImageWatermarker::canProcess(),
                ]);
                $finalPath = $path;
            } else {
                $finalPath = $this->discoverFeaturedImagePathOnDisk($news->id);
            }
        }

        if ($finalPath === null || ! FeaturedImageValidator::isValidRelativePath($finalPath)) {
            $this->purgeInvalidFeaturedImageFiles($news->id);
            Log::warning('featured_image: no hay archivo utilizable tras descarga', [
                'news_id' => $news->id,
                'path' => $path,
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
        return $this->findFeaturedImagePathForNews($news) !== null;
    }

    public function findFeaturedImagePathForNews(News $news): ?string
    {
        $news->loadMissing('detail');

        $fromStored = $this->resolveExistingStoragePath($news->detail?->featured_image_path);

        if ($fromStored !== null) {
            return $fromStored;
        }

        return $this->discoverFeaturedImagePathOnDisk($news->id);
    }

    public function syncFeaturedImagePathFromDisk(News $news): ?string
    {
        $path = $this->findFeaturedImagePathForNews($news);

        if ($path === null || $news->detail === null) {
            return null;
        }

        if ($news->detail->featured_image_path !== $path) {
            $news->detail->update([
                'featured_image_path' => $path,
                'featured_image_source' => $news->detail->featured_image_source ?? 'scraped',
            ]);
        }

        return $path;
    }

    public function discoverFeaturedImagePathOnDisk(int $newsId): ?string
    {
        foreach (['webp', 'jpg', 'jpeg', 'png', 'gif'] as $extension) {
            $path = "featured-images/{$newsId}.{$extension}";

            if (FeaturedImageValidator::isValidRelativePath($path)) {
                return $path;
            }
        }

        return null;
    }

    public function purgeInvalidFeaturedImageFiles(int $newsId): void
    {
        foreach (['webp', 'jpg', 'jpeg', 'png', 'gif'] as $extension) {
            $path = "featured-images/{$newsId}.{$extension}";

            if (Storage::disk('public')->exists($path) && ! FeaturedImageValidator::isValidRelativePath($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    public function ensureFeaturedImageReady(News $news, bool $skipGenerate = false): ?string
    {
        $news->loadMissing('detail');

        $this->purgeInvalidFeaturedImageFiles($news->id);

        $existing = $this->findFeaturedImagePathForNews($news);

        if ($existing !== null) {
            return $this->syncFeaturedImagePathFromDisk($news);
        }

        if ($news->detail?->status !== 'processed') {
            return null;
        }

        $result = $this->processForNews($news, $skipGenerate);

        if (in_array($result, ['downloaded', 'generated'], true)) {
            $news->refresh();
            $news->load('detail');

            return $this->findFeaturedImagePathForNews($news);
        }

        return $this->syncFeaturedImagePathFromDisk($news);
    }

    /**
     * @param  list<int>  $newsIds
     */
    public function ensureBatchReady(array $newsIds, bool $skipGenerate = false): void
    {
        foreach ($newsIds as $newsId) {
            $news = News::query()->with('detail')->find($newsId);

            if ($news !== null) {
                $this->ensureFeaturedImageReady($news, $skipGenerate);
            }
        }
    }

    public function resolveExistingStoragePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (FeaturedImageValidator::isValidRelativePath($path)) {
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

            if (FeaturedImageValidator::isValidRelativePath($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function downloadFirstWorkingCandidate(News $news, array $candidates): ?string
    {
        foreach ($candidates as $imageUrl) {
            $result = $this->imageDownloader->downloadToFeaturedPath($imageUrl, $news->id, $news->url);

            if ($result['path'] !== null) {
                return $result['path'];
            }

            Log::info('featured_image: candidato no descargable', [
                'news_id' => $news->id,
                'url' => $imageUrl,
                'http_status' => $result['status'],
                'bytes' => $result['bytes'],
                'reason' => $result['reason'],
            ]);

            usleep(200_000);
        }

        return null;
    }
}
