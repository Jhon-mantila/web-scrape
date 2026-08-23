<?php

namespace App\ProcessScraping\Research;

class SearchQueryBuilder
{
    /**
     * @return list<string>
     */
    public function build(string $title, string $contentText, ?string $source = null, ?string $category = null): array
    {
        $queries = [];
        $cleanTitle = $this->cleanTitle($title);

        if ($cleanTitle !== '') {
            $queries[] = $cleanTitle;
        }

        if ($source === 'anime_news') {
            $queries[] = $cleanTitle.' anime';
            $queries[] = $cleanTitle.' anime news español';
        } elseif ($category !== null && $category !== '') {
            $queries[] = $cleanTitle.' '.$category;
        } else {
            $queries[] = mb_substr($cleanTitle, 0, 80).' anime';
        }

        $keywordQuery = $this->keywordQuery($contentText, $cleanTitle);
        if ($keywordQuery !== null) {
            $queries[] = $keywordQuery;
        }

        $queries = array_values(array_unique(array_filter(array_map(
            fn (string $q) => trim(preg_replace('/\s+/', ' ', $q) ?? $q),
            $queries
        ))));

        return array_slice($queries, 0, (int) config('services.searxng.max_queries', 3));
    }

    private function cleanTitle(string $title): string
    {
        $title = preg_replace('/^(EXCLUSIVE|REVIEW|INTERVIEW|VIDEO|FEATURE):\s*/i', '', $title) ?? $title;
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;

        return trim($title);
    }

    private function keywordQuery(string $contentText, string $cleanTitle): ?string
    {
        $sample = mb_strtolower(mb_substr($contentText, 0, 500));

        $keywords = [
            'temporada', 'trailer', 'estreno', 'película', 'pelicula', 'anime',
            'manga', 'studio', 'studios', 'crunchyroll', 'netflix',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($sample, $keyword)) {
                return $cleanTitle !== '' ? $cleanTitle.' '.$keyword : $keyword.' anime';
            }
        }

        return null;
    }
}
