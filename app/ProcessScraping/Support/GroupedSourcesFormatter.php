<?php

namespace App\ProcessScraping\Support;

class GroupedSourcesFormatter
{
    /**
     * @param  list<array{news_id?: int, title?: string, url?: string, content_text?: string}>|null  $sources
     */
    public static function toPromptBlock(?array $sources): string
    {
        if ($sources === null || $sources === []) {
            return '';
        }

        $blocks = ['NOTICIAS AGRUPADAS (misma franquicia; trátalas como un solo artículo):'];

        foreach ($sources as $index => $source) {
            $n = $index + 1;
            $title = trim((string) ($source['title'] ?? ''));
            $url = trim((string) ($source['url'] ?? ''));
            $text = trim((string) ($source['content_text'] ?? ''));
            $text = mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 2500);

            $blocks[] = "--- Agrupada {$n}: {$title}";
            if ($url !== '') {
                $blocks[] = "URL: {$url}";
            }
            $blocks[] = $text;
            $blocks[] = '';
        }

        return implode("\n", $blocks);
    }
}
