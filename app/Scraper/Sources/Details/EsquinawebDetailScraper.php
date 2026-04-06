<?php

namespace App\Scraper\Sources\Details;

use App\Models\News;
use App\Scraper\Services\BaseScraper;
use App\Scraper\Sources\Contracts\DetailScraperInterface;
use Symfony\Component\DomCrawler\Crawler;

class EsquinawebDetailScraper extends BaseScraper implements DetailScraperInterface
{
    public function scrape(News $news): array
    {
        $html = $this->get($news->url);
        $crawler = new Crawler($html);

        $rawHtml = null;
        foreach (['.entry-content', '.post-content', 'article', 'main', 'body'] as $selector) {
            if ($crawler->filter($selector)->count() > 0) {
                $rawHtml = $crawler->filter($selector)->first()->html();
                break;
            }
        }

        $contentText = $this->normalizeText($rawHtml ?? ($crawler->filter('body')->count() > 0 ? $crawler->filter('body')->html() : ''));

        return [
            'raw_html' => $rawHtml,
            'content_text' => $contentText !== '' ? $contentText : null,
        ];
    }

    private function normalizeText(?string $html): string
    {
        if (!is_string($html) || trim($html) === '') {
            return '';
        }

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim((string) $text);
    }
}

