<?php

namespace App\Scraper\Sources\Contracts;

use App\Models\News;

interface DetailScraperInterface
{
    /**
     * @return array{raw_html:?string, content_text:?string}
     */
    public function scrape(News $news): array;
}

