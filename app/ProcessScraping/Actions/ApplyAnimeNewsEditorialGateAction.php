<?php

namespace App\ProcessScraping\Actions;

use App\Models\News;
use App\ProcessScraping\Support\AnimeNewsFranchiseResolver;
use App\ProcessScraping\Support\AnimeNewsInterestFilter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApplyAnimeNewsEditorialGateAction
{
    public function __construct(
        private readonly AnimeNewsFranchiseResolver $franchiseResolver,
        private readonly AnimeNewsInterestFilter $interestFilter,
    ) {}

    /**
     * Filtra por popularidad y agrupa por franquicia. Devuelve IDs listos para IA.
     *
     * @param  list<int>  $batchIds
     * @return array{
     *     eligible_ids: list<int>,
     *     filtered: int,
     *     grouped: int,
     *     groups: int
     * }
     */
    public function execute(array $batchIds): array
    {
        if (
            ! config('services.scraper.anime_news_staged')
            || ! config('services.scraper.anime_news_editorial_gate_enabled', true)
            || $batchIds === []
        ) {
            return [
                'eligible_ids' => $batchIds,
                'filtered' => 0,
                'grouped' => 0,
                'groups' => 0,
            ];
        }

        $hours = max(1, (int) config('services.scraper.anime_news_group_hours', 72));
        $since = Carbon::now()->subHours($hours);

        $pool = News::query()
            ->where('source', 'anime_news')
            ->whereNull('merged_into_news_id')
            ->where(function ($q) {
                $q->whereNull('status_ia')
                    ->orWhereNotIn('status_ia', ['merged', 'skipped_low_interest', 'skipped_short', 'skipped_quality']);
            })
            ->where('created_at', '>=', $since)
            ->whereHas('detail', fn ($q) => $q->where('status', 'processed')->whereNotNull('content_text'))
            ->with('detail')
            ->orderBy('id')
            ->get();

        $filtered = 0;
        $grouped = 0;

        foreach ($pool as $news) {
            if ($news->status_ia === 'skipped_low_interest') {
                continue;
            }

            if (! $this->interestFilter->shouldProcess($news)) {
                $news->update(['status_ia' => 'skipped_low_interest']);
                $filtered++;
                Log::info('editorial: noticia descartada por interés', [
                    'news_id' => $news->id,
                    'reason' => $this->interestFilter->skipReason($news),
                ]);
            }
        }

        $pool = News::query()
            ->where('source', 'anime_news')
            ->whereNull('merged_into_news_id')
            ->where(function ($q) {
                $q->whereNull('status_ia')
                    ->orWhereNotIn('status_ia', ['merged', 'skipped_low_interest', 'skipped_short', 'skipped_quality']);
            })
            ->where('created_at', '>=', $since)
            ->whereHas('detail', fn ($q) => $q->where('status', 'processed')->whereNotNull('content_text'))
            ->with('detail')
            ->orderBy('id')
            ->get();

        /** @var array<string, list<News>> $byKey */
        $byKey = [];

        foreach ($pool as $news) {
            $key = $this->franchiseResolver->franchiseKey($news);

            if ($key === null) {
                continue;
            }

            $byKey[$key][] = $news;
        }

        $groupsCount = 0;

        foreach ($byKey as $key => $items) {
            if (count($items) < 2) {
                continue;
            }

            $groupsCount++;
            usort($items, fn (News $a, News $b) => $a->id <=> $b->id);
            /** @var News $primary */
            $primary = array_pop($items);

            DB::transaction(function () use ($items, $primary, &$grouped): void {
                $sources = [];

                foreach ($items as $merged) {
                    if ($merged->id === $primary->id) {
                        continue;
                    }

                    $merged->update([
                        'merged_into_news_id' => $primary->id,
                        'status_ia' => 'merged',
                    ]);

                    $detail = $merged->detail;

                    if ($detail !== null) {
                        $sources[] = [
                            'news_id' => $merged->id,
                            'title' => $merged->title,
                            'url' => $merged->url,
                            'content_text' => $detail->content_text,
                        ];
                    }

                    $grouped++;
                }

                if ($sources === []) {
                    return;
                }

                $existing = $primary->detail?->grouped_sources;

                if (! is_array($existing)) {
                    $existing = [];
                }

                $mergedIds = array_column($existing, 'news_id');
                $newSources = array_values(array_filter(
                    $sources,
                    fn (array $row) => ! in_array($row['news_id'], $mergedIds, true),
                ));

                if ($newSources === []) {
                    return;
                }

                $primary->detail?->update([
                    'grouped_sources' => array_merge($existing, $newSources),
                    'fact_sheet' => null,
                ]);

                Log::info('editorial: noticias agrupadas en primaria', [
                    'primary_id' => $primary->id,
                    'merged_ids' => array_column($newSources, 'news_id'),
                ]);
            });
        }

        $eligible = News::query()
            ->whereIn('id', $batchIds)
            ->where('source', 'anime_news')
            ->whereNull('merged_into_news_id')
            ->where(function ($q) {
                $q->whereNull('status_ia')
                    ->orWhereNotIn('status_ia', ['merged', 'skipped_low_interest', 'skipped_short', 'skipped_quality']);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return [
            'eligible_ids' => $eligible,
            'filtered' => $filtered,
            'grouped' => $grouped,
            'groups' => $groupsCount,
        ];
    }
}
