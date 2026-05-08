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
        $baseUrl = config('scraper.anime_news.base_url');
        $newsUrl = config('scraper.anime_news.news_url');

        $html = $this->get($newsUrl);

        $crawler = new Crawler($html);

        $news = [];
        $that = $this;
        $crawler->filter('.herald.box.news')->each(function (Crawler $node) use (&$news, $baseUrl) {
            
            //Log::info('cantidad de noticias: ' . $crawler->filter('.herald.box.news')->count());

            $url = $node->filter('h3 a')->count() 
            ? $baseUrl . $node->filter('h3 a')->attr('href') 
            : null;

            // 🚫 FILTRO AQUÍ
            if ($url && str_contains($url, '/cms/discuss/')) {
                return; // salta este registro
            }

            $title = $node->filter('h3 a')->count() 
                ? trim($node->filter('h3 a')->text()) 
                : null;

            /*$image = $node->filter('.thumbnail')->count()
            ? $this->extractImageFromStyle($node->filter('.thumbnail')->attr('style'))
            : null;*/
            
            $category = $node->filter('.topics a')->count() 
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
