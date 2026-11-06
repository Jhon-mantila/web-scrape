<?php

namespace App\ProcessScraping\Support;

/**
 * Evita que nombres internos (BD, tools, JSON) aparezcan en título, extracto o cuerpo publicables.
 */
class GeneratedArticleLeakGuard
{
    /** @var list<string> */
    private const LEAK_TERMS = [
        'research_context',
        'research_raw',
        'researched_at',
        'news_detail_id',
        'news_details',
        'news_id',
        'raw_html',
        'content_text',
        'featured_image_path',
        'consultar_investigacion',
        'research context',
    ];

    /**
     * @param  array{generated_title?: ?string, excerpt?: ?string, body_html?: ?string}  $parts
     * @return array{generated_title?: ?string, excerpt?: ?string, body_html?: ?string}
     */
    public static function scrubParts(array $parts): array
    {
        if (isset($parts['generated_title']) && is_string($parts['generated_title'])) {
            $parts['generated_title'] = self::scrub($parts['generated_title']);
        }

        if (isset($parts['excerpt']) && is_string($parts['excerpt'])) {
            $parts['excerpt'] = self::scrub($parts['excerpt']);
        }

        if (isset($parts['body_html']) && is_string($parts['body_html'])) {
            $parts['body_html'] = self::scrub($parts['body_html']);
        }

        return $parts;
    }

    public static function scrub(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $out = preg_replace('/\bnews_detail_id\s*[=:]\s*\d+/iu', '', $text) ?? $text;
        $out = preg_replace('/\bnews_id\s*[=:]\s*\d+/iu', '', $out) ?? $out;

        foreach (self::LEAK_TERMS as $term) {
            $out = preg_replace('/\b'.preg_quote($term, '/').'\b/iu', '', $out) ?? $out;
        }

        $out = preg_replace('/\s{2,}/u', ' ', $out) ?? $out;
        $out = preg_replace('/\(\s*\)/u', '', $out) ?? $out;

        return trim($out);
    }

    public static function findLeak(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $haystack = mb_strtolower(strip_tags($text));

        if (preg_match('/\bnews_detail_id\s*[=:]\s*\d+/iu', $text)) {
            return 'news_detail_id';
        }

        if (preg_match('/\bnews_id\s*[=:]\s*\d+/iu', $text)) {
            return 'news_id';
        }

        foreach (self::LEAK_TERMS as $term) {
            if (str_contains($haystack, mb_strtolower($term))) {
                return $term;
            }
        }

        return null;
    }

    /**
     * @param  array{generated_title?: ?string, excerpt?: ?string, body_html?: ?string}  $parts
     */
    public static function validateParts(array $parts): ?string
    {
        foreach (['generated_title' => 'título', 'excerpt' => 'extracto', 'body_html' => 'cuerpo'] as $key => $label) {
            $value = $parts[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $leak = self::findLeak($value);

            if ($leak !== null) {
                return "El {$label} contiene referencias internas del sistema ({$leak}); debe redactarse para lectores.";
            }
        }

        return null;
    }
}
