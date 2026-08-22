<?php

namespace App\ProcessScraping\Support;

class HtmlArticleSanitizer
{
    /**
     * @param  list<string>  $allowedEmbedUrls  URLs de YouTube extraídas del scrape. Si está vacío, se eliminan todos los embeds.
     */
    public static function sanitize(?string $html, array $allowedEmbedUrls = []): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        $allowedIds = self::extractVideoIds($allowedEmbedUrls);

        $html = self::removeUnauthorizedYoutubeEmbeds($html, $allowedIds);

        if ($allowedIds === []) {
            return trim($html);
        }

        $html = self::fixBrokenIframes($html);
        $html = self::normalizeIframes($html, $allowedIds);
        $html = self::removeProblematicAttributes($html);

        return trim($html);
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    public static function extractVideoIds(array $urls): array
    {
        $ids = [];

        foreach ($urls as $url) {
            $id = self::parseVideoId($url);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function parseVideoId(string $url): ?string
    {
        if (preg_match('/(?:embed\/|watch\?v=|youtu\.be\/)([\w\-]{11})/i', $url, $match)) {
            $id = $match[1];

            return self::isValidVideoId($id) ? $id : null;
        }

        return null;
    }

    private static function isValidVideoId(string $id): bool
    {
        if (! preg_match('/^[\w\-]{11}$/', $id)) {
            return false;
        }

        if (preg_match('/^0+$/', $id)) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $allowedIds
     */
    private static function removeUnauthorizedYoutubeEmbeds(string $html, array $allowedIds): string
    {
        if ($allowedIds === []) {
            return self::stripAllYoutubeEmbeds($html);
        }

        $html = preg_replace_callback(
            '/<figure\b[^>]*>.*?<\/figure>/is',
            function (array $match) use ($allowedIds): string {
                return self::containsAllowedYoutube($match[0], $allowedIds) ? $match[0] : '';
            },
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '/<iframe\b[^>]*>.*?<\/iframe>/is',
            function (array $match) use ($allowedIds): string {
                return self::containsAllowedYoutube($match[0], $allowedIds) ? $match[0] : '';
            },
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '/<iframe\b[^>]*\/?>/i',
            function (array $match) use ($allowedIds): string {
                return self::containsAllowedYoutube($match[0], $allowedIds) ? $match[0] : '';
            },
            $html
        ) ?? $html;

        return $html;
    }

    /**
     * @param  list<string>  $allowedIds
     */
    private static function containsAllowedYoutube(string $fragment, array $allowedIds): bool
    {
        if (! str_contains(mb_strtolower($fragment), 'youtube')) {
            return true;
        }

        if (preg_match('/src=["\']([^"\']+)["\']/i', $fragment, $match)) {
            $id = self::parseVideoId(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5));

            return $id !== null && in_array($id, $allowedIds, true);
        }

        return false;
    }

    private static function stripAllYoutubeEmbeds(string $html): string
    {
        $patterns = [
            '/<figure\b[^>]*>.*?youtube.*?<\/figure>/is',
            '/<div\b[^>]*wp-block-embed[^>]*>.*?youtube.*?<\/div>/is',
            '/<iframe\b[^>]*youtube[^>]*>.*?<\/iframe>/is',
            '/<iframe\b[^>]*youtube[^>]*\/?>/i',
            '/<p>\s*<\/p>/i',
        ];

        foreach ($patterns as $pattern) {
            $html = preg_replace($pattern, '', $html) ?? $html;
        }

        $html = preg_replace('/\n{3,}/', "\n\n", $html) ?? $html;

        return trim($html);
    }

    private static function fixBrokenIframes(string $html): string
    {
        $html = preg_replace(
            '/<p([^>]*)>\s*<iframe([^>]*?)>\s*<\/p>/i',
            '<figure class="wp-block-embed is-type-video is-provider-youtube"><iframe$2></iframe></figure>',
            $html
        );

        $html = preg_replace(
            '/<iframe([^>]*?)>\s*<\/p>/i',
            '<iframe$1></iframe></p>',
            $html
        );

        return $html ?? $html;
    }

    /**
     * @param  list<string>  $allowedIds
     */
    private static function normalizeIframes(string $html, array $allowedIds): string
    {
        return preg_replace_callback('/<iframe\b[^>]*>/i', function (array $matches) use ($allowedIds): string {
            $tag = $matches[0];

            if (! preg_match('/src=["\']([^"\']+)["\']/i', $tag, $srcMatch)) {
                return '';
            }

            $src = html_entity_decode($srcMatch[1], ENT_QUOTES | ENT_HTML5);
            $id = self::parseVideoId($src);

            if ($id === null || ! in_array($id, $allowedIds, true)) {
                return '';
            }

            $embedSrc = 'https://www.youtube.com/embed/'.$id;

            return '<figure class="wp-block-embed is-type-video is-provider-youtube wp-embed-aspect-16-9">'
                .'<div class="wp-block-embed__wrapper">'
                .'<iframe src="'.htmlspecialchars($embedSrc, ENT_QUOTES | ENT_HTML5).'" '
                .'width="640" height="360" frameborder="0" '
                .'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
                .'allowfullscreen></iframe>'
                .'</div></figure>';
        }, $html) ?? $html;
    }

    private static function removeProblematicAttributes(string $html): string
    {
        $html = preg_replace('/\salign="center"/i', '', $html);
        $html = preg_replace('/\sstyle="[^"]*position:\s*absolute[^"]*"/i', '', $html);
        $html = preg_replace('/\sreferrerpolicy="[^"]*"/i', '', $html);

        return $html ?? $html;
    }
}
