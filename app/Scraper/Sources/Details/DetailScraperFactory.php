<?php

namespace App\Scraper\Sources\Details;

use App\Models\News;
use App\Scraper\Sources\Contracts\DetailScraperInterface;

class DetailScraperFactory
{
    public function for(News $news): DetailScraperInterface
    {
        return match ((string) $news->source) {
            'anime_news' => new AnimeNewsDetailScraper(),
            'esquinaweb' => new EsquinawebDetailScraper(),
            default => throw new \RuntimeException('No detail scraper registered for source: ' . (string) $news->source),
        };
    }
}

