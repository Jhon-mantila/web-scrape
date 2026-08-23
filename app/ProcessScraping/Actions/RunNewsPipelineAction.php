<?php

namespace App\ProcessScraping\Actions;

use App\Scraper\Actions\ScrapeNewsAction;
use App\Scraper\Actions\ScrapeNewsDetailsAction;
use App\ProcessScraping\Ai\OllamaClient;
use App\ProcessScraping\Images\Generators\ComfyUIClient;
use App\ProcessScraping\Support\PipelineBatchResolver;
use App\SendWordpress\Actions\SendPostToWordpressAction;

class RunNewsPipelineAction
{
    public function __construct(
        private readonly ScrapeNewsAction $scrapeNews,
        private readonly ScrapeNewsDetailsAction $scrapeDetails,
        private readonly DownloadFeaturedImagesAction $downloadImages,
        private readonly ResearchNewsAction $research,
        private readonly GenerateNewsAiArticleAction $generateAi,
        private readonly SendPostToWordpressAction $sendWordpress,
        private readonly ComfyUIClient $comfyui,
        private readonly OllamaClient $ollama,
        private readonly PipelineBatchResolver $batchResolver,
    ) {}

    /**
     * @return array{
     *     scrape_news: int,
     *     details: array{processed: int, success: int, failed: int},
     *     images: array{processed: int, downloaded: int, generated: int, success: int, skipped: int, failed: int},
     *     research: array{processed: int, success: int, skipped: int, failed: int},
     *     ai: array{processed: int, success: int, failed: int, news_ids: list<int>, errors: list<array{news_id: int, message: string}>},
     *     wordpress: array{processed: int, success: int, failed: int}
     * }
     */
    public function execute(
        int $limit,
        string $mode,
        bool $force,
        bool $includeRawHtml,
        bool $skipScrape,
        bool $skipResearch,
        bool $skipGenerate = false,
    ): array {
        $scrapeNewsCount = 0;

        if (! $skipScrape) {
            $scrapeNewsCount = count($this->scrapeNews->execute());
        }

        $details = $this->scrapeDetails->execute($limit, $force);
        $images = $this->downloadImages->execute($limit, $skipGenerate);

        if (
            config('services.comfyui.free_memory_after_images')
            && $this->comfyui->isEnabled()
            && $images['processed'] > 0
        ) {
            $this->comfyui->freeMemory();
        }

        $batchIds = $this->batchResolver->resolveForAi($limit, $force);

        $research = $skipResearch
            ? ['processed' => 0, 'success' => 0, 'skipped' => 0, 'failed' => 0]
            : $this->research->execute($limit, $force, $batchIds);
        $ai = $this->generateAi->execute($limit, $force, $includeRawHtml, $batchIds);

        if (config('services.ollama.unload_after_generate') && $ai['processed'] > 0) {
            $this->ollama->unloadModels();
        }

        $wordpress = $this->sendWordpress->execute(
            $limit,
            $mode,
            $ai['news_ids'] ?? [],
        );

        return [
            'scrape_news' => $scrapeNewsCount,
            'details' => $details,
            'images' => $images,
            'research' => $research,
            'ai' => $ai,
            'wordpress' => $wordpress,
        ];
    }
}
