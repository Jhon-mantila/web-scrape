<?php

namespace App\ProcessScraping\Research;

use App\Models\News;
use App\ProcessScraping\Ai\Support\AiGenerationOptions;
use App\ProcessScraping\Ai\Support\JsonPayloadParser;
use App\ProcessScraping\Prompts\EntityExtractionPrompt;
use App\ProcessScraping\Ai\Contracts\TextGenerationClient;
use Illuminate\Support\Facades\Log;

class AnimeNewsResearchOrchestrator
{
    public function __construct(
        private readonly SearXngClient $searxng,
        private readonly SearchQueryBuilder $queryBuilder,
        private readonly EntityDirectedSearchBuilder $directedQueries,
        private readonly AniListClient $anilist,
        private readonly WebPageTextFetcher $pageFetcher,
        private readonly EnhancedResearchFormatter $formatter,
        private readonly JsonPayloadParser $jsonParser,
    ) {}

    /**
     * @return array{context: ?string, raw: array<string, mixed>}|null
     */
    public function gather(News $news, TextGenerationClient $llm, ?string $model): ?array
    {
        $detail = $news->detail;

        if ($detail === null || $detail->content_text === null) {
            return null;
        }

        $entities = $this->extractEntities($news, $llm, $model);
        $anilist = null;

        if ($entities !== null) {
            $franchise = trim((string) ($entities['franchise'] ?? ''));
            $mediaType = (string) ($entities['media_type'] ?? 'anime');

            if ($franchise !== '') {
                $anilist = $this->anilist->lookupMedia($franchise, $mediaType);
            }
        }

        $searches = [];
        $queryList = $entities !== null
            ? $this->directedQueries->build($entities)
            : $this->queryBuilder->build($news->title, $detail->content_text, $news->source, $news->category);

        if ($queryList === []) {
            $queryList = $this->queryBuilder->build($news->title, $detail->content_text, $news->source, $news->category);
        }

        $resultsPerQuery = (int) config('services.searxng.results_per_query', 3);

        foreach ($queryList as $query) {
            $results = $this->searxng->search($query, $resultsPerQuery);
            $searches[] = compact('query', 'results');
        }

        $pages = $this->fetchTopPages($searches);

        $context = $this->formatter->format($searches, $entities, $anilist, $pages);

        if ($context === null) {
            return null;
        }

        return [
            'context' => $context,
            'raw' => [
                'mode' => 'anime_news_staged',
                'entities' => $entities,
                'anilist' => $anilist,
                'searches' => $searches,
                'pages' => $pages,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractEntities(News $news, TextGenerationClient $llm, ?string $model): ?array
    {
        try {
            $raw = $llm->generate(
                EntityExtractionPrompt::system(),
                EntityExtractionPrompt::user(
                    $news->title,
                    (string) $news->detail?->content_text,
                    $news->category,
                ),
                $model,
                AiGenerationOptions::forEntityExtraction(),
            );

            $parsed = $this->jsonParser->parse($raw);

            return is_array($parsed) ? $parsed : null;
        } catch (\Throwable $e) {
            Log::warning('research: extracción de entidades falló', [
                'news_id' => $news->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  list<array{query: string, results: list<array{title: string, url: string, content: string, engine: string}>}>  $searches
     * @return list<array{url: string, title: string, text: string}>
     */
    private function fetchTopPages(array $searches): array
    {
        $maxPages = max(0, (int) config('services.research.page_fetch_max', 2));
        $seen = [];
        $pages = [];

        foreach ($searches as $search) {
            foreach ($search['results'] as $result) {
                $url = trim($result['url']);

                if ($url === '' || isset($seen[$url])) {
                    continue;
                }

                $seen[$url] = true;
                $page = $this->pageFetcher->fetch($url);

                if ($page !== null) {
                    $pages[] = $page;
                }

                if (count($pages) >= $maxPages) {
                    return $pages;
                }
            }
        }

        return $pages;
    }
}
