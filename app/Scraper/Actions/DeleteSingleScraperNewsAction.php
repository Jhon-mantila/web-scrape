<?php

namespace App\Scraper\Actions;

use App\Models\News;
use App\ProcessScraping\Support\AiGenerationProgress;
use App\Scraper\Support\NewsScraperBulkDelete;
use Illuminate\Support\Collection;

class DeleteSingleScraperNewsAction
{
    public function __construct(
        private readonly NewsScraperBulkDelete $bulkDelete,
    ) {}

    /**
     * @return array{news_deleted: int, images_deleted: int}
     */
    public function execute(News $news): array
    {
        $news->loadMissing(['detail', 'aiArticle']);

        AiGenerationProgress::forget($news->id);

        return $this->bulkDelete->deleteMany(new Collection([$news]));
    }
}
