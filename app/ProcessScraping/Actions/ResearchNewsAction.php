<?php

namespace App\ProcessScraping\Actions;

use App\Models\News;
use App\ProcessScraping\Research\ResearchContextFormatter;
use App\ProcessScraping\Research\SearXngClient;
use App\ProcessScraping\Research\SearchQueryBuilder;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResearchNewsAction
{
    public function __construct(
        private readonly SearXngClient $searxng,
        private readonly SearchQueryBuilder $queryBuilder,
        private readonly ResearchContextFormatter $formatter,
    ) {}

    /**
     * @return array{processed: int, success: int, skipped: int, failed: int}
     */
    public function execute(int $limit, bool $force): array
    {
        if (! config('services.searxng.enabled')) {
            return ['processed' => 0, 'success' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $processed = 0;
        $success = 0;
        $skipped = 0;
        $failed = 0;

        $query = News::query()
            ->whereHas('detail', function ($q) use ($force) {
                $q->where('status', 'processed')
                    ->whereNotNull('content_text');

                if (! $force) {
                    $q->whereNull('research_context');
                }
            })
            ->with('detail')
            ->orderBy('id')
            ->limit(max($limit, 1));

        foreach ($query->get() as $news) {
            $processed++;

            try {
                $result = $this->researchForNews($news);

                if ($result === null) {
                    $skipped++;
                    continue;
                }

                $news->detail?->update([
                    'research_context' => $result['context'],
                    'research_raw' => $result['raw'],
                    'researched_at' => now(),
                ]);

                $success++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning('research: fallo', [
                    'news_id' => $news->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return compact('processed', 'success', 'skipped', 'failed');
    }

    /**
     * @return array{context: ?string, raw: array}|null
     */
    public function researchForNews(News $news): ?array
    {
        $detail = $news->detail;
        if ($detail === null || $detail->content_text === null) {
            return null;
        }

        $queries = $this->queryBuilder->build(
            $news->title,
            $detail->content_text,
            $news->source,
            $news->category,
        );

        $searches = [];
        $resultsPerQuery = (int) config('services.searxng.results_per_query', 3);

        foreach ($queries as $query) {
            $results = $this->searxng->search($query, $resultsPerQuery);
            $searches[] = compact('query', 'results');
        }

        $context = $this->formatter->format($searches);

        if ($context === null) {
            return null;
        }

        return [
            'context' => $context,
            'raw' => $searches,
        ];
    }
}
