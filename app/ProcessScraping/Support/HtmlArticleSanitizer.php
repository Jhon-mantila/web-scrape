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
            return self::collapseEmptyParagraphs(trim($html));
        }

        $html = self::fixBrokenIframes($html);
        $html = self::normalizeFigureEmbeds($html, $allowedIds);
        $html = self::normalizeBareIframes($html, $allowedIds);
        $html = self::removeProblematicAttributes($html);
        $html = self::collapseWhitespaceAroundEmbeds($html);
        $html = self::collapseEmptyParagraphs($html);

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
        if (preg_match('#(?:embed/|watch\?v=|youtu\.be/)([\w-]{11})#i', $url, $match)) {
            $id = $match[1];

            return self::isValidVideoId($id) ? $id : null;
        }

        return null;
    }

    private static function isValidVideoId(string $id): bool
    {
        if (strlen($id) !== 11) {
            return false;
        }

        if (! preg_match('#^[\w-]+$#', $id)) {
            return false;
        }

        if (preg_match('#^0+$#', $id)) {
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

        if (preg_match('#src=(["\'])(.+?)\1#i', $fragment, $match)) {
            $id = self::parseVideoId(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5));

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

        return self::collapseEmptyParagraphs(trim($html));
    }

    private static function fixBrokenIframes(string $html): string
    {
        $html = preg_replace(
            '/<p([^>]*)>\s*<iframe([^>]*?)>\s*<\/p>/i',
            '<figure class="wp-block-embed is-type-video is-provider-youtube"><iframe$2></iframe></figure>',
            $html
        );

        $html = preg_replace(
            '/<p([^>]*)>\s*<figure\b/i',
            '<figure',
            $html
        ) ?? $html;

        $html = preg_replace(
            '/<\/figure>\s*<\/p>/i',
            '</figure>',
            $html
        ) ?? $html;

        $html = preg_replace(
            '/<iframe([^>]*?)>\s*<\/p>/i',
            '<iframe$1></iframe>',
            $html
        );

        return $html ?? $html;
    }

    /**
     * @param  list<string>  $allowedIds
     */
    private static function normalizeFigureEmbeds(string $html, array $allowedIds): string
    {
        return preg_replace_callback(
            '/<figure\b[^>]*>.*?youtube.*?<\/figure>/is',
            function (array $match) use ($allowedIds): string {
                if (! preg_match('#src=(["\'])(.+?)\1#i', $match[0], $srcMatch)) {
                    return '';
                }

                $id = self::parseVideoId(html_entity_decode($srcMatch[2], ENT_QUOTES | ENT_HTML5));

                if ($id === null || ! in_array($id, $allowedIds, true)) {
                    return '';
                }

                return self::buildCompactEmbed('https://www.youtube.com/embed/'.$id);
            },
            $html
        ) ?? $html;
    }

    /**
     * Solo iframes sueltos (fuera de <figure>); evita anidar dos figures.
     *
     * @param  list<string>  $allowedIds
     */
    private static function normalizeBareIframes(string $html, array $allowedIds): string
    {
        if (! str_contains(mb_strtolower($html), '<iframe')) {
            return $html;
        }

        $parts = preg_split('/(<figure\b[^>]*>.*?<\/figure>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $html;
        }

        $result = '';

        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                $result .= $part;

                continue;
            }

            $result .= preg_replace_callback('/<iframe\b[^>]*>/i', function (array $matches) use ($allowedIds): string {
                $tag = $matches[0];

                if (! preg_match('#src=(["\'])(.+?)\1#i', $tag, $srcMatch)) {
                    return '';
                }

                $src = html_entity_decode($srcMatch[2], ENT_QUOTES | ENT_HTML5);
                $id = self::parseVideoId($src);

                if ($id === null || ! in_array($id, $allowedIds, true)) {
                    return '';
                }

                return self::buildCompactEmbed('https://www.youtube.com/embed/'.$id);
            }, $part) ?? $part;
        }

        return $result;
    }

    private static function buildCompactEmbed(string $embedSrc): string
    {
        $src = htmlspecialchars($embedSrc, ENT_QUOTES | ENT_HTML5);

        return '<figure class="wp-block-embed is-type-video is-provider-youtube" '
            .'style="margin:0.75em 0;max-width:100%;">'
            .'<iframe src="'.$src.'" '
            .'width="100%" height="360" loading="lazy" frameborder="0" '
            .'style="display:block;width:100%;max-width:100%;aspect-ratio:16/9;border:0;vertical-align:top;" '
            .'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
            .'allowfullscreen></iframe>'
            .'</figure>';
    }

    private static function collapseWhitespaceAroundEmbeds(string $html): string
    {
        $html = preg_replace('/(<figure\b[^>]*>.*?<\/figure>\s*)+/is', '$1', $html) ?? $html;

        $html = preg_replace('/(\s*<br\s*\/?>\s*)+(<figure\b)/i', '$2', $html) ?? $html;
        $html = preg_replace('/(<\/figure>)(\s*<br\s*\/?>\s*)+/i', '$1', $html) ?? $html;

        $html = preg_replace('/(<\/p>\s*)+(<figure\b)/i', '</p>$2', $html) ?? $html;
        $html = preg_replace('/(<\/figure>)(\s*<p)/i', '$1$2', $html) ?? $html;

        $html = preg_replace('/<p([^>]*)>\s*(<figure\b)/i', '$2', $html) ?? $html;
        $html = preg_replace('/(<\/figure>)\s*<\/p>/i', '$1', $html) ?? $html;

        $html = preg_replace('/<div\b[^>]*class="[^"]*wp-block-embed__wrapper[^"]*"[^>]*>\s*(<iframe)/i', '$1', $html) ?? $html;
        $html = preg_replace('/(<\/iframe>)\s*<\/div>\s*(<\/figure>)/i', '$1$2', $html) ?? $html;

        return $html;
    }

    private static function collapseEmptyParagraphs(string $html): string
    {
        $html = preg_replace('/<p[^>]*>\s*(?:<br\s*\/?>\s*)*<\/p>/i', '', $html) ?? $html;
        $html = preg_replace('/\n{3,}/', "\n\n", $html) ?? $html;

        return trim($html);
    }

    private static function removeProblematicAttributes(string $html): string
    {
        $html = preg_replace('/\salign="center"/i', '', $html);
        $html = preg_replace('/\sstyle="[^"]*position:\s*absolute[^"]*"/i', '', $html);
        $html = preg_replace('/\sreferrerpolicy="[^"]*"/i', '', $html);
        $html = preg_replace('/\swp-embed-aspect-16-9\b/i', '', $html);
        $html = preg_replace('/\swp-has-aspect-ratio\b/i', '', $html);

        return $html ?? $html;
    }
}
