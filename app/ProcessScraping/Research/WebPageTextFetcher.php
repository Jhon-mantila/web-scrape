<?php

namespace App\ProcessScraping\Research;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebPageTextFetcher
{
    /** @var list<string> */
    private const BLOCKED_HOST_FRAGMENTS = [
        'animenewsnetwork.com',
        'youtube.com',
        'youtu.be',
        'twitter.com',
        'x.com',
        'facebook.com',
        'instagram.com',
        'tiktok.com',
    ];

    /**
     * @return array{url: string, title: string, text: string}|null
     */
    public function fetch(string $url): ?array
    {
        $url = trim($url);

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        if ($this->isBlockedUrl($url)) {
            return null;
        }

        try {
            $response = Http::timeout((int) config('services.research.page_fetch_timeout', 18))
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; EsquinaAnimeResearch/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url);
        } catch (\Throwable $e) {
            Log::debug('research: fetch página falló', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $contentType = mb_strtolower((string) $response->header('Content-Type'));

        if ($contentType !== '' && ! str_contains($contentType, 'text/html') && ! str_contains($contentType, 'application/xhtml')) {
            return null;
        }

        $html = (string) $response->body();
        $maxBytes = (int) config('services.research.page_fetch_max_bytes', 800_000);

        if (strlen($html) > $maxBytes) {
            $html = substr($html, 0, $maxBytes);
        }

        $title = $this->extractTitle($html);
        $text = $this->htmlToText($html);
        $maxChars = (int) config('services.research.page_max_chars', 6000);
        $text = mb_substr($text, 0, $maxChars);

        if (mb_strlen(trim($text)) < 200) {
            return null;
        }

        return [
            'url' => $url,
            'title' => $title,
            'text' => trim($text),
        ];
    }

    private function isBlockedUrl(string $url): bool
    {
        $lower = mb_strtolower($url);

        foreach (self::BLOCKED_HOST_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function extractTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<(script|style|noscript|svg)[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<\/?(p|div|h[1-6]|li|blockquote|br)[^>]*>/i', "\n", $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
