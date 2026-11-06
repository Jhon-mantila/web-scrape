<?php

namespace App\ProcessScraping\Support;

class YoutubeExtractor
{
    /** @var list<string> */
    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'from', 'that', 'this', 'will', 'have', 'been',
        'are', 'was', 'not', 'but', 'you', 'all', 'can', 'her', 'his', 'its', 'our',
        'anime', 'manga', 'news', 'video', 'official', 'trailer', 'preview', 'episode',
        'season', 'temporada', 'capitulo', 'capítulo', 'serie', 'pelicula', 'película',
        'nuevo', 'nueva', 'about', 'como', 'para', 'del', 'las', 'los', 'una', 'uno',
    ];

    /**
     * Embeds permitidos: primero el HTML de la noticia; luego YouTube de investigación
     * solo si el título/snippet del resultado encaja con el titular o el contenido scrapeado.
     *
     * @return list<string>
     */
    public static function collect(
        ?string $html,
        ?string $researchContext = null,
        mixed $researchRaw = null,
        ?string $newsTitle = null,
        ?string $newsContentSnippet = null,
    ): array {
        $embeds = self::extract($html);
        $reference = self::buildReference($newsTitle, $newsContentSnippet);

        if ($reference === '') {
            return $embeds;
        }

        foreach (self::collectFromResearchContext($researchContext, $reference) as $embed) {
            self::appendUnique($embeds, $embed);
        }

        foreach (self::collectFromResearchRaw($researchRaw, $reference) as $embed) {
            self::appendUnique($embeds, $embed);
        }

        return $embeds;
    }

    public static function extract(?string $html): array
    {
        if (empty($html)) {
            return [];
        }

        $embeds = [];

        preg_match_all(
            '/<iframe[^>]+src=["\']([^"\']*youtube\.com\/embed\/[^"\']+)["\']/i',
            $html,
            $iframes
        );

        foreach ($iframes[1] as $src) {
            $clean = preg_replace('/[?&]si=[^&]+/', '', $src);
            $clean = rtrim($clean, '?&');
            self::appendUnique($embeds, $clean);
        }

        foreach (self::extractFromText($html) as $embed) {
            self::appendUnique($embeds, $embed);
        }

        return $embeds;
    }

    /**
     * @return list<string>
     */
    public static function extractFromText(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $embeds = [];

        preg_match_all(
            '/https?:\/\/(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([\w\-]{11})/i',
            $text,
            $links
        );

        foreach ($links[1] as $videoId) {
            self::appendUnique($embeds, 'https://www.youtube.com/embed/'.$videoId);
        }

        return $embeds;
    }

    private static function buildReference(?string $title, ?string $content): string
    {
        $title = trim((string) $title);
        $snippet = mb_substr(trim((string) $content), 0, 1200);

        return trim($title.' '.$snippet);
    }

    /**
     * @return list<string>
     */
    private static function collectFromResearchContext(?string $context, string $reference): array
    {
        if ($context === null || trim($context) === '') {
            return [];
        }

        $embeds = [];

        foreach (preg_split('/\r\n|\r|\n/', $context) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains(mb_strtolower($line), 'youtube')) {
                continue;
            }

            if (! self::isRelevantHaystack($line, $reference)) {
                continue;
            }

            foreach (self::extractFromText($line) as $embed) {
                self::appendUnique($embeds, $embed);
            }
        }

        return $embeds;
    }

    /**
     * @return list<string>
     */
    private static function collectFromResearchRaw(mixed $researchRaw, string $reference): array
    {
        if (! is_array($researchRaw)) {
            return [];
        }

        $embeds = [];

        foreach ($researchRaw as $search) {
            if (! is_array($search)) {
                continue;
            }

            foreach ($search['results'] ?? [] as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $url = (string) ($result['url'] ?? '');

                if (! str_contains(mb_strtolower($url), 'youtube')) {
                    continue;
                }

                $haystack = trim(
                    ($result['title'] ?? '').' '.($result['content'] ?? '').' '.$url,
                );

                if (! self::isRelevantHaystack($haystack, $reference)) {
                    continue;
                }

                foreach (self::extractFromText($url) as $embed) {
                    self::appendUnique($embeds, $embed);
                }
            }
        }

        return $embeds;
    }

    private static function isRelevantHaystack(string $haystack, string $reference): bool
    {
        $refTokens = self::significantTokens($reference);

        if ($refTokens === []) {
            return false;
        }

        $hay = mb_strtolower($haystack);
        $matched = 0;

        foreach ($refTokens as $token) {
            if (str_contains($hay, $token)) {
                $matched++;
            }
        }

        if ($matched >= 2) {
            return true;
        }

        if ($matched === 1) {
            foreach ($refTokens as $token) {
                if (str_contains($hay, $token) && mb_strlen($token) >= 6) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function significantTokens(string $text): array
    {
        $lower = mb_strtolower($text);

        if (! preg_match_all('/\p{L}[\p{L}\p{N}\'-]{2,}/u', $lower, $matches)) {
            return [];
        }

        $unique = [];

        foreach ($matches[0] as $word) {
            if (in_array($word, self::STOPWORDS, true)) {
                continue;
            }

            if (mb_strlen($word) < 3) {
                continue;
            }

            $unique[$word] = true;
        }

        return array_keys($unique);
    }

    /**
     * @param  list<string>  $embeds
     */
    private static function appendUnique(array &$embeds, string $embedUrl): void
    {
        if (! in_array($embedUrl, $embeds, true)) {
            $embeds[] = $embedUrl;
        }
    }
}
