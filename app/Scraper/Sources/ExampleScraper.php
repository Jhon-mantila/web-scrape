<?php

// app/Scraper/Sources/ExampleScraper.php
namespace App\Scraper\Sources;

use App\Scraper\Contracts\ScraperInterface;
use App\Scraper\Services\BaseScraper;
use App\Scraper\DTO\NewsDTO;
use Symfony\Component\DomCrawler\Crawler;

class ExampleScraper extends BaseScraper implements ScraperInterface
{
    public function scrape(): array
    {
        $html = $this->get('https://example.com');

        $crawler = new Crawler($html);

        $news = [];

        $crawler->filter('h2')->each(function (Crawler $node) use (&$news) {
            $news[] = new NewsDTO(
                title: $node->text(),
                url: '#'
            );
        });

        return $news;
    }
}