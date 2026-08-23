<?php

namespace App\ProcessScraping\Support;

use App\Models\News;

class PipelineBatchResolver
{
    /**
     * Noticias listas para investigación + IA en este lote del pipeline.
     *
     * @return list<int>
     */
    public function resolveForAi(int $limit, bool $force): array
    {
        $query = News::query()
            ->select('news.id')
            ->join('news_details as nd', 'nd.news_id', '=', 'news.id')
            ->whereNotNull('nd.raw_html')
            ->whereNotNull('nd.content_text')
            ->where('nd.status', 'processed')
            ->orderBy('news.id');

        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('news.status_ia')
                    ->orWhere('news.status_ia', 'failed');
            });
        }

        return $query
            ->limit(max($limit, 1))
            ->pluck('news.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
