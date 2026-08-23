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
     *     wordpress: array{processed: int, success: int, failed: int, scheduled: list<mixed>, by_author: array<string, int>},
     *     timings: array<string, float>
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
        $timings = [];
        $scrapeNewsCount = 0;

        if (! $skipScrape) {
            $stepStarted = microtime(true);
            $scrapeNewsCount = count($this->scrapeNews->execute());
            $timings['scrape'] = microtime(true) - $stepStarted;
        }

        $stepStarted = microtime(true);
        $details = $this->scrapeDetails->execute($limit, $force);
        $timings['details'] = microtime(true) - $stepStarted;

        $stepStarted = microtime(true);
        $images = $this->downloadImages->execute($limit, $skipGenerate);
        $timings['images'] = microtime(true) - $stepStarted;

        if (
            config('services.comfyui.free_memory_after_images')
            && $this->comfyui->isEnabled()
            && $images['processed'] > 0
        ) {
            $this->comfyui->freeMemory();
        }

        $batchIds = $this->batchResolver->resolveForAi($limit, $force);

        if ($skipResearch) {
            $research = ['processed' => 0, 'success' => 0, 'skipped' => 0, 'failed' => 0];
        } else {
            $stepStarted = microtime(true);
            $research = $this->research->execute($limit, $force, $batchIds);
            $timings['research'] = microtime(true) - $stepStarted;
        }

        $stepStarted = microtime(true);
        $ai = $this->generateAi->execute($limit, $force, $includeRawHtml, $batchIds);
        $timings['ai'] = microtime(true) - $stepStarted;

        if (config('services.ollama.unload_after_generate') && $ai['processed'] > 0) {
            $this->ollama->unloadModels();
        }

        $stepStarted = microtime(true);
        $wordpress = $this->sendWordpress->execute(
            $limit,
            $mode,
            $ai['news_ids'] ?? [],
        );
        $timings['wordpress'] = microtime(true) - $stepStarted;

        return [
            'scrape_news' => $scrapeNewsCount,
            'details' => $details,
            'images' => $images,
            'research' => $research,
            'ai' => $ai,
            'wordpress' => $wordpress,
            'timings' => $timings,
        ];
    }
}
