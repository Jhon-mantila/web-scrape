<?php

namespace App\Scraper\Sources;

use App\Scraper\Contracts\ScraperInterface;
use App\Scraper\DTO\NewsDTO;
use App\Scraper\Services\BaseScraper;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class EsquinaAnimeScraper extends BaseScraper implements ScraperInterface
{
    public function scrape(): array
    {
        $html = $this->get('https://esquinaweb.com/anime/');


        $crawler = new Crawler($html);

        $news = [];

        $crawler->filter('.pt-cv-content-item')->each(function (Crawler $node) use (&$news) {
            
            $title = $node->filter('h4')->count() 
                ? trim($node->filter('h4')->text()) 
                : null;

            $url = $node->filter('a')->count() 
                ? $node->filter('a')->attr('href') 
                : null;


            if ($title && $url) {
                $news[] = new NewsDTO(
                    title: $title,
                    url: $url,
                    source: 'esquinaweb'

                );
            }
        });

        return $news;
    }
}
