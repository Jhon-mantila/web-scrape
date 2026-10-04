<?php

namespace App\Scraper\Actions;

use App\Models\News;
use App\Models\NewsDetail;
use App\ProcessScraping\Actions\DownloadFeaturedImagesAction;
use App\Scraper\Sources\Details\DetailScraperFactory;
use Illuminate\Support\Facades\Log;

class ScrapeNewsDetailsAction
{
    public function __construct(
        private readonly ?DetailScraperFactory $factory = null,
        private readonly ?DownloadFeaturedImagesAction $downloadImages = null,
    ) {}

    /**
     * @return array{processed:int, success:int, failed:int}
     */
    public function execute(int $limit = 30, bool $force = false): array
    {
        $items = $this->getCandidates($limit, $force);
        $summary = ['processed' => 0, 'success' => 0, 'failed' => 0];

        foreach ($items as $news) {
            $summary['processed']++;

            $detail = NewsDetail::firstOrCreate(
                ['news_id' => $news->id],
                ['status' => 'pending', 'attempt_count' => 0]
            );

            try {
                $result = $this->factory()->for($news)->scrape($news);

                $detail->update([
                    'status' => 'processed',
                    'raw_html' => $result['raw_html'],
                    'content_text' => $result['content_text'],
                    'attempt_count' => $detail->attempt_count + 1,
                    'last_error' => null,
                    'scraped_at' => now(),
                ]);

                $summary['success']++;

                $news->refresh()->load('detail');
                $this->downloadImages()->downloadForNews($news);
            } catch (\Throwable $e) {
                $detail->update([
                    'status' => 'failed',
                    'attempt_count' => $detail->attempt_count + 1,
                    'last_error' => $e->getMessage(),
                ]);

                Log::error('Failed scraping news detail', [
                    'news_id' => $news->id,
                    'url' => $news->url,
                    'message' => $e->getMessage(),
                ]);

                $summary['failed']++;
            }
        }

        return $summary;
    }

    private function getCandidates(int $limit, bool $force)
    {
        if ($force) {
            return News::query()->latest('id')->limit($limit)->get();
        }

        return News::query()
            ->whereDoesntHave('detail', function ($q) {
                $q->where('status', 'processed');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function factory(): DetailScraperFactory
    {
        return $this->factory ?? new DetailScraperFactory();
    }

    private function downloadImages(): DownloadFeaturedImagesAction
    {
        return $this->downloadImages ?? app(DownloadFeaturedImagesAction::class);
    }
}
