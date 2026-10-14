<?php

namespace Tests\Unit;

use App\Models\News;
use App\Models\NewsDetail;
use App\ProcessScraping\Support\AnimeNewsFranchiseResolver;
use App\ProcessScraping\Support\AnimeNewsInterestFilter;
use Tests\TestCase;

class AnimeNewsInterestFilterTest extends TestCase
{
    public function test_keeps_low_popularity_homenaje(): void
    {
        config(['services.scraper.anime_news_filter_enabled' => true]);
        config(['services.scraper.anime_news_min_anilist_popularity' => 8000]);

        $news = new News([
            'source' => 'anime_news',
        ]);

        $news->setRelation('detail', new NewsDetail([
            'research_raw' => [
                'entities' => ['news_type' => 'homenaje'],
                'anilist' => ['popularity' => 100],
            ],
        ]));

        $filter = new AnimeNewsInterestFilter(new AnimeNewsFranchiseResolver);

        $this->assertTrue($filter->shouldProcess($news));
    }

    public function test_skips_low_popularity_announcement(): void
    {
        config(['services.scraper.anime_news_filter_enabled' => true]);
        config(['services.scraper.anime_news_min_anilist_popularity' => 8000]);

        $news = new News([
            'source' => 'anime_news',
        ]);

        $news->setRelation('detail', new NewsDetail([
            'research_raw' => [
                'entities' => ['news_type' => 'anuncio'],
                'anilist' => ['popularity' => 500],
            ],
        ]));

        $filter = new AnimeNewsInterestFilter(new AnimeNewsFranchiseResolver);

        $this->assertFalse($filter->shouldProcess($news));
    }
}
