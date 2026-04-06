<?php

namespace App\Scraper\Actions;

use App\Models\News;
use App\Models\NewsDetail;
use App\Scraper\Sources\Details\DetailScraperFactory;
use Illuminate\Support\Facades\Log;

class ScrapeNewsDetailsAction
{
    public function __construct(private readonly ?DetailScraperFactory $factory = null) {}

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
        $query = News::query()->latest('id');

        if ($force) {
            return $query->limit($limit)->get();
        }

        return $query
            ->whereDoesntHave('detail', function ($q) {
                $q->where('status', 'processed');
            })
            ->limit($limit)
            ->get();
    }

    private function factory(): DetailScraperFactory
    {
        return $this->factory ?? new DetailScraperFactory();
    }
}
