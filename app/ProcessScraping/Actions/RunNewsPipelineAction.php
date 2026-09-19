<?php

namespace App\ProcessScraping\Actions;

use App\Scraper\Actions\ScrapeNewsAction;
use App\Scraper\Actions\ScrapeNewsDetailsAction;
use App\ProcessScraping\Ai\OllamaClient;
use App\ProcessScraping\Images\Generators\ComfyUIClient;
use App\ProcessScraping\Support\PipelineBatchResolver;
use App\ProcessScraping\Support\PipelineRunState;
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
        bool $skipWordpress = false,
        ?int $progressUserId = null,
    ): array {
        $timings = [];
        $scrapeNewsCount = 0;

        if (! $skipScrape) {
            $this->progressBegin($progressUserId, 'scrape', 'Scrapeando listado de noticias…');
            $stepStarted = microtime(true);
            $scrapeNewsCount = count($this->scrapeNews->execute());
            $timings['scrape'] = microtime(true) - $stepStarted;
            $this->progressFinish($progressUserId, 'scrape', "Listado: {$scrapeNewsCount} nuevas");
        } else {
            $this->progressSkip($progressUserId, 'scrape');
        }

        $this->progressBegin($progressUserId, 'details', 'Extrayendo detalle de noticias…');
        $stepStarted = microtime(true);
        $details = $this->scrapeDetails->execute($limit, $force);
        $timings['details'] = microtime(true) - $stepStarted;
        $this->progressFinish(
            $progressUserId,
            'details',
            "Detalles OK {$details['success']}/{$details['processed']}",
        );

        $this->progressBegin($progressUserId, 'images', 'Descargando imágenes destacadas…');
        $stepStarted = microtime(true);
        $images = $this->downloadImages->execute($limit, $skipGenerate);
        $timings['images'] = microtime(true) - $stepStarted;
        $this->progressFinish(
            $progressUserId,
            'images',
            "Imágenes {$images['downloaded']} desc. · {$images['generated']} FLUX",
        );

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
            $this->progressSkip($progressUserId, 'research');
        } else {
            $this->progressBegin($progressUserId, 'research', 'Investigando contexto (SearXNG)…');
            $stepStarted = microtime(true);
            $research = $this->research->execute($limit, $force, $batchIds);
            $timings['research'] = microtime(true) - $stepStarted;
            $this->progressFinish(
                $progressUserId,
                'research',
                "Research OK {$research['success']}/{$research['processed']}",
            );
        }

        $this->progressBegin($progressUserId, 'ai', 'Generando artículos con IA…');
        $stepStarted = microtime(true);
        $ai = $this->generateAi->execute($limit, $force, $includeRawHtml, $batchIds);
        $timings['ai'] = microtime(true) - $stepStarted;
        $this->progressFinish(
            $progressUserId,
            'ai',
            "IA OK {$ai['success']}/{$ai['processed']}",
        );

        if (config('services.ollama.unload_after_generate') && $ai['processed'] > 0) {
            $this->ollama->unloadModels();
        }

        if ($skipWordpress) {
            $wordpress = [
                'processed' => 0,
                'success' => 0,
                'failed' => 0,
                'scheduled' => [],
                'by_author' => [],
            ];
            $this->progressSkip($progressUserId, 'wordpress');
        } else {
            $this->progressBegin($progressUserId, 'wordpress', 'Enviando a WordPress…');
            $stepStarted = microtime(true);
            $wordpress = $this->sendWordpress->execute(
                $limit,
                $mode,
                $ai['news_ids'] ?? [],
            );
            $timings['wordpress'] = microtime(true) - $stepStarted;
            $this->progressFinish(
                $progressUserId,
                'wordpress',
                "WP OK {$wordpress['success']}/{$wordpress['processed']}",
            );
        }

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

    private function progressBegin(?int $userId, string $step, string $message): void
    {
        if ($userId === null) {
            return;
        }

        PipelineRunState::beginStep($userId, $step, $message);
    }

    private function progressFinish(?int $userId, string $step, string $detail): void
    {
        if ($userId === null) {
            return;
        }

        PipelineRunState::finishStep($userId, $step, $detail);
    }

    private function progressSkip(?int $userId, string $step): void
    {
        if ($userId === null) {
            return;
        }

        PipelineRunState::skipStep($userId, $step);
    }
}
