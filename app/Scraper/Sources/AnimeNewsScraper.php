<?php

// app/Scraper/Sources/AnimeNewsScraper.php

namespace App\Scraper\Sources;

use App\Scraper\Contracts\ScraperInterface;
use App\Scraper\Services\BaseScraper;
use App\Scraper\DTO\NewsDTO;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;

class AnimeNewsScraper extends BaseScraper implements ScraperInterface
{
    public function scrape(): array
    {
        $html = $this->get('https://www.animenewsnetwork.com/news/');

    
        $crawler = new Crawler($html);

        $news = [];

        $crawler->filter('.herald.box.news')->each(function (Crawler $node) use (&$news) {
            
            $title = $node->filter('h3')->count() 
                ? trim($node->filter('h3')->text()) 
                : null;

            $url = $node->filter('a')->count() 
                ? 'https://www.animenewsnetwork.com' . $node->filter('a')->attr('href') 
                : null;

            $image = $node->filter('img')->count() 
                ? $node->filter('img')->attr('src') 
                : null;
            
                $category = $node->filter('.topics')->count() 
                ? trim($node->filter('.topics a')->first()->text()) 
                : null;
          
            //Log::info('category: ' . $category);

            if ($title && $url) {
                $news[] = new NewsDTO(
                    title: $title,
                    url: $url,
                    source: 'anime_news',
                    category: $category

                );
            }
        });

        return $news;
    }
}
