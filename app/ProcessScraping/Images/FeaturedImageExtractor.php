<?php

namespace App\ProcessScraping\Images;

use App\Models\News;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class FeaturedImageExtractor
{
    public function extract(News $news): ?string
    {
        $candidates = $this->collectCandidates($news);

        return $candidates[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function collectCandidates(News $news): array
    {
        $baseUrl = $news->url;
        $candidates = [];

        if ($news->image !== null && $news->image !== '') {
            $candidates[] = $this->toAbsoluteUrl($news->image, $baseUrl);
        }

        $detail = $news->detail;

        if ($detail?->raw_html !== null && $detail->raw_html !== '') {
            foreach ($this->extractMetaImageUrls($detail->raw_html, $baseUrl) as $url) {
                $candidates[] = $url;
            }

            foreach ($this->extractImgSrcUrls($detail->raw_html, $baseUrl) as $url) {
                $candidates[] = $url;
            }
        }

        if ($candidates === [] && $news->url !== null && $news->url !== '') {
            $fromPage = $this->extractFromFullPage($news->url);

            if ($fromPage !== null) {
                $candidates[] = $fromPage;
            }
        }

        return $this->uniqueUrls($candidates);
    }

    /**
     * Último recurso cuando raw_html no tenía URLs útiles.
     */
    public function collectCandidatesFromLivePage(News $news): array
    {
        if ($news->url === null || $news->url === '') {
            return [];
        }

        $fromPage = $this->extractFromFullPage($news->url);

        return $fromPage !== null ? [$fromPage] : [];
    }

    private function extractFromFullPage(string $pageUrl): ?string
    {
        try {
            $response = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; AnimeScraper/1.0)'])
                ->get($pageUrl);

            if ($response->failed()) {
                return null;
            }

            $candidates = $this->extractMetaImageUrls($response->body(), $pageUrl);

            if ($candidates !== []) {
                return $candidates[0];
            }

            $imgs = $this->extractImgSrcUrls($response->body(), $pageUrl);

            return $imgs[0] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function extractMetaImageUrls(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html);
            $urls = [];

            foreach ([
                ['meta[property="og:image"]', 'content'],
                ['meta[property="og:image:url"]', 'content'],
                ['meta[name="twitter:image"]', 'content'],
                ['meta[name="twitter:image:src"]', 'content'],
                ['link[rel="image_src"]', 'href'],
            ] as [$selector, $attr]) {
                if ($crawler->filter($selector)->count() === 0) {
                    continue;
                }

                $url = trim((string) $crawler->filter($selector)->first()->attr($attr));

                if ($url !== '') {
                    $urls[] = $this->toAbsoluteUrl($url, $baseUrl);
                }
            }

            return $this->uniqueUrls($urls);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function extractImgSrcUrls(string $html, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($html);
            $urls = [];

            if ($crawler->filter('img')->count() === 0) {
                return [];
            }

            $crawler->filter('img')->each(function (Crawler $node) use (&$urls, $baseUrl): void {
                foreach (['src', 'data-src', 'data-lazy-src'] as $attr) {
                    $src = trim((string) $node->attr($attr));

                    if ($src === '' || str_starts_with($src, 'data:')) {
                        continue;
                    }

                    $lower = mb_strtolower($src);

                    if (str_contains($lower, 'pixel') || str_contains($lower, 'spacer') || str_contains($lower, 'icon')) {
                        continue;
                    }

                    $urls[] = $this->toAbsoluteUrl($src, $baseUrl);
                }
            });

            return $this->uniqueUrls($urls);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function uniqueUrls(array $urls): array
    {
        $seen = [];
        $out = [];

        foreach ($urls as $url) {
            $url = trim($url);

            if ($url === '' || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $out[] = $url;
        }

        return $out;
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
