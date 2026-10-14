<?php

namespace App\ProcessScraping\Support;

use App\Models\News;

class AnimeNewsInterestFilter
{
    public function __construct(
        private readonly AnimeNewsFranchiseResolver $franchise,
    ) {}

    public function shouldProcess(News $news): bool
    {
        if (! config('services.scraper.anime_news_filter_enabled', true)) {
            return true;
        }

        if ($news->source !== 'anime_news') {
            return true;
        }

        $type = $this->franchise->newsType($news);

        if ($type !== null && in_array($type, ['homenaje', 'industry', 'polemica', 'polémica'], true)) {
            return true;
        }

        $popularity = $this->franchise->anilistPopularity($news);

        if ($popularity === null) {
            return true;
        }

        $min = (int) config('services.scraper.anime_news_min_anilist_popularity', 8000);

        return $popularity >= $min;
    }

    public function skipReason(News $news): string
    {
        $popularity = $this->franchise->anilistPopularity($news);
        $min = (int) config('services.scraper.anime_news_min_anilist_popularity', 8000);

        return "Interés bajo en AniList (popularidad {$popularity}; mínimo {$min}).";
    }
}
