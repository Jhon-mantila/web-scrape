<?php

namespace App\ProcessScraping\Actions;

use App\Models\News;
use App\Models\NewsAiArticle;
use App\Models\NewsDetail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ResetNewsProcessingAction
{
    /**
     * Limpia imágenes, investigación e IA pero conserva news y news_details scrapeados.
     *
     * @return array{
     *     images_deleted: int,
     *     details_updated: int,
     *     ai_articles_deleted: int,
     *     news_status_reset: int
     * }
     */
    public function execute(bool $images = true, bool $research = true, bool $ai = true): array
    {
        $imagesDeleted = 0;

        if ($images) {
            $imagesDeleted = $this->deleteFeaturedImages();
        }

        $detailsUpdated = 0;

        if ($images || $research) {
            $updates = [];

            if ($images) {
                $updates['featured_image_path'] = null;
                $updates['featured_image_source'] = null;
            }

            if ($research) {
                $updates['research_context'] = null;
                $updates['research_raw'] = null;
                $updates['researched_at'] = null;
            }

            if ($updates !== []) {
                $detailsUpdated = NewsDetail::query()->update($updates);
            }
        }

        $aiDeleted = 0;
        $newsStatusReset = 0;

        if ($ai) {
            $aiDeleted = NewsAiArticle::query()->count();
            NewsAiArticle::query()->delete();
            $newsStatusReset = News::query()
                ->whereNotNull('status_ia')
                ->update(['status_ia' => null]);
        }

        return [
            'images_deleted' => $imagesDeleted,
            'details_updated' => $detailsUpdated,
            'ai_articles_deleted' => $aiDeleted,
            'news_status_reset' => $newsStatusReset,
        ];
    }

    /**
     * Limpieza total: imágenes + TRUNCATE de news, news_details y news_ai_articles.
     *
     * @return array{
     *     images_deleted: int,
     *     news_truncated: int,
     *     details_truncated: int,
     *     ai_articles_truncated: int
     * }
     */
    public function truncateAll(): array
    {
        $imagesDeleted = $this->deleteFeaturedImages();

        $newsCount = News::query()->count();
        $detailsCount = NewsDetail::query()->count();
        $aiCount = NewsAiArticle::query()->count();

        Schema::disableForeignKeyConstraints();

        try {
            NewsAiArticle::truncate();
            NewsDetail::truncate();
            News::truncate();
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return [
            'images_deleted' => $imagesDeleted,
            'news_truncated' => $newsCount,
            'details_truncated' => $detailsCount,
            'ai_articles_truncated' => $aiCount,
        ];
    }

    private function deleteFeaturedImages(): int
    {
        $disk = Storage::disk('public');
        $deleted = 0;

        if (! $disk->exists('featured-images')) {
            return 0;
        }

        foreach ($disk->files('featured-images') as $path) {
            if ($disk->delete($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
