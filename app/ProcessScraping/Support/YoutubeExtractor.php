<?php

namespace App\ProcessScraping\Support;

class YoutubeExtractor
{
    /**
     * Recopila embeds de YouTube desde el scrape, la investigación SearXNG y el JSON crudo.
     *
     * @return list<string>
     */
    public static function collect(
        ?string $html,
        ?string $researchContext = null,
        mixed $researchRaw = null,
    ): array {
        $embeds = self::extract($html);

        foreach (self::extractFromText($researchContext) as $embed) {
            self::appendUnique($embeds, $embed);
        }

        if (is_array($researchRaw)) {
            foreach ($researchRaw as $search) {
                if (! is_array($search)) {
                    continue;
                }

                foreach ($search['results'] ?? [] as $result) {
                    if (! is_array($result)) {
                        continue;
                    }

                    foreach (self::extractFromText($result['url'] ?? null) as $embed) {
                        self::appendUnique($embeds, $embed);
                    }
                }
            }
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
