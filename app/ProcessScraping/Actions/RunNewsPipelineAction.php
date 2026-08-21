<?php

namespace App\ProcessScraping\Actions;

use App\Scraper\Actions\ScrapeNewsAction;
use App\Scraper\Actions\ScrapeNewsDetailsAction;
use App\SendWordpress\Actions\SendPostToWordpressAction;

class RunNewsPipelineAction
{
    public function __construct(
        private readonly ScrapeNewsAction $scrapeNews,
        private readonly ScrapeNewsDetailsAction $scrapeDetails,
        private readonly DownloadFeaturedImagesAction $downloadImages,
        private readonly GenerateNewsAiArticleAction $generateAi,
        private readonly SendPostToWordpressAction $sendWordpress,
    ) {}

    /**
     * @return array{
     *     scrape_news: int,
     *     details: array{processed: int, success: int, failed: int},
     *     images: array{processed: int, success: int, skipped: int, failed: int},
     *     ai: array{processed: int, success: int, failed: int, errors: list<array{news_id: int, message: string}>},
     *     wordpress: array{processed: int, success: int, failed: int}
     * }
     */
    public function execute(
        int $limit,
        string $mode,
        bool $force,
        bool $includeRawHtml,
        bool $skipScrape,
    ): array {
        $scrapeNewsCount = 0;

        if (! $skipScrape) {
            $scrapeNewsCount = count($this->scrapeNews->execute());
        }

        $details = $this->scrapeDetails->execute($limit, $force);
        $images = $this->downloadImages->execute($limit);
        $ai = $this->generateAi->execute($limit, $force, $includeRawHtml);
        $wordpress = $this->sendWordpress->execute($limit, $mode);

        return [
            'scrape_news' => $scrapeNewsCount,
            'details' => $details,
            'images' => $images,
            'ai' => $ai,
            'wordpress' => $wordpress,
        ];
    }
}
