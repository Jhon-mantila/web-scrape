<?php

namespace App\Scraper\Support;

use App\Models\News;
use App\ProcessScraping\Actions\DownloadFeaturedImagesAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class NewsScraperBulkDelete
{
    public function __construct(
        private readonly DownloadFeaturedImagesAction $featuredImages,
    ) {}

    /**
     * @param  Collection<int, News>  $items
     * @return array{news_deleted: int, images_deleted: int}
     */
    public function deleteMany(Collection $items): array
    {
        $ids = $items->pluck('id')->all();

        if ($ids !== []) {
            News::query()
                ->whereIn('merged_into_news_id', $ids)
                ->update(['merged_into_news_id' => null]);
        }

        $newsDeleted = 0;
        $imagesDeleted = 0;

        foreach ($items as $news) {
            if ($this->featuredImages->featuredImageExists($news)) {
                $imagesDeleted++;
            }

            $this->featuredImages->removeFeaturedImage($news);

            DB::transaction(function () use ($news): void {
                $news->aiArticle?->delete();
                $news->detail?->delete();
                $news->delete();
            });

            $newsDeleted++;
        }

        return [
            'news_deleted' => $newsDeleted,
            'images_deleted' => $imagesDeleted,
        ];
    }
}
