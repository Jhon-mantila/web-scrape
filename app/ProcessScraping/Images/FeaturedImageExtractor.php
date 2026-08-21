<?php

namespace App\ProcessScraping\Images;

use App\Models\News;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class FeaturedImageExtractor
{
    public function extract(News $news): ?string
    {
        if ($news->image !== null && $news->image !== '') {
            return $this->toAbsoluteUrl($news->image, $news->url);
        }

        $fromPage = $this->extractFromFullPage($news->url);
        if ($fromPage !== null) {
            return $fromPage;
        }

        $detail = $news->detail;
        if ($detail?->raw_html !== null && $detail->raw_html !== '') {
            return $this->extractFromHtml($detail->raw_html, $news->url);
        }

        return null;
    }

    private function extractFromFullPage(string $pageUrl): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; AnimeScraper/1.0)'])
                ->get($pageUrl);

            if ($response->failed()) {
                return null;
            }

            $crawler = new Crawler($response->body());

            foreach ([
                ['meta[property="og:image"]', 'content'],
                ['meta[name="twitter:image"]', 'content'],
                ['link[rel="image_src"]', 'href'],
            ] as [$selector, $attr]) {
                if ($crawler->filter($selector)->count() > 0) {
                    $url = trim((string) $crawler->filter($selector)->first()->attr($attr));
                    if ($url !== '') {
                        return $this->toAbsoluteUrl($url, $pageUrl);
                    }
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function extractFromHtml(string $html, string $baseUrl): ?string
    {
        try {
            $crawler = new Crawler($html);
            $best = null;

            if ($crawler->filter('img')->count() === 0) {
                return null;
            }

            $crawler->filter('img')->each(function (Crawler $node) use (&$best, $baseUrl): void {
                $src = trim((string) $node->attr('src'));
                if ($src === '' || str_starts_with($src, 'data:')) {
                    return;
                }

                $lower = mb_strtolower($src);
                if (str_contains($lower, 'pixel') || str_contains($lower, 'spacer') || str_contains($lower, 'icon')) {
                    return;
                }

                $best = $this->toAbsoluteUrl($src, $baseUrl);
            });

            return $best ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function toAbsoluteUrl(string $url, string $baseUrl): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';

        if (str_starts_with($url, '//')) {
            return $scheme.':'.$url;
        }

        if (str_starts_with($url, '/')) {
            return $scheme.'://'.$host.$url;
        }

        $path = $parts['path'] ?? '/';
        $dir = rtrim(dirname($path), '/');

        return $scheme.'://'.$host.$dir.'/'.$url;
    }
}
