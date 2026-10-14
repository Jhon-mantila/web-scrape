<?php

namespace App\Scraper\Actions;

use App\Models\News;
use App\ProcessScraping\Actions\DownloadFeaturedImagesAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class DeleteFullyPublishedScraperNewsAction
{
    public function __construct(
        private readonly DownloadFeaturedImagesAction $featuredImages,
    ) {}

    /**
     * Noticias anime con pipeline completo y publicadas en WP (no programadas ni borrador).
     *
     * @return Builder<News>
     */
    public function eligibleQuery(): Builder
    {
        return News::query()
            ->where('source', 'anime_news')
            ->where('status_ia', 'processed')
            ->whereHas('detail', function (Builder $query): void {
                $query
                    ->where('status', 'processed')
                    ->whereNotNull('researched_at')
                    ->whereNotNull('featured_image_path');
            })
            ->whereHas('aiArticle', function (Builder $query): void {
                $query
                    ->where('sent_wordpress', true)
                    ->where('wordpress_status', 'publish')
                    ->whereNotNull('body_html');
            });
    }

    /**
     * @return Collection<int, News>
     */
    public function eligibleNews(): Collection
    {
        $items = $this->eligibleQuery()
            ->with(['detail', 'aiArticle'])
            ->orderBy('id')
            ->get();

        return $items->filter(fn (News $news) => $this->featuredImages->featuredImageExists($news))->values();
    }

    public function countEligible(): int
    {
        return $this->eligibleNews()->count();
    }

    /**
     * @return array{news_deleted: int, images_deleted: int}
     */
    public function execute(): array
    {
        $newsDeleted = 0;
        $imagesDeleted = 0;

        foreach ($this->eligibleNews() as $news) {
            $path = $news->detail?->featured_image_path;

            if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
                if (Storage::disk('public')->delete($path)) {
                    $imagesDeleted++;
                }
            }

            $news->aiArticle?->delete();
            $news->detail?->delete();
            $news->delete();
            $newsDeleted++;
        }

        return [
            'news_deleted' => $newsDeleted,
            'images_deleted' => $imagesDeleted,
        ];
    }
}
