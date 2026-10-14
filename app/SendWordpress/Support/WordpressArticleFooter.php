<?php

namespace App\SendWordpress\Support;

use App\Models\News;
use App\Models\NewsAiArticle;

class WordpressArticleFooter
{
    public static function append(string $html, NewsAiArticle $article): string
    {
        $news = $article->news;

        if ($news === null) {
            return $html;
        }

        $blocks = [];

        $sources = self::sourcesBlock($news);
        if ($sources !== '') {
            $blocks[] = $sources;
        }

        $imageCredit = self::imageCreditBlock($news);
        if ($imageCredit !== '') {
            $blocks[] = $imageCredit;
        }

        if ($blocks === []) {
            return $html;
        }

        $footer = '<section class="esquinaanime-article-footer" aria-label="Fuentes y créditos">'
            .implode('', $blocks)
            .'</section>';

        return rtrim($html)."\n".$footer;
    }

    private static function sourcesBlock(News $news): string
    {
        $items = [];
        $url = trim((string) $news->url);

        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            $label = $news->source === 'anime_news'
                ? 'Anime News Network'
                : 'Fuente original';

            $items[] = sprintf(
                '<li><a href="%s" rel="noopener noreferrer" target="_blank">%s</a></li>',
                e($url),
                e($label),
            );
        }

        $grouped = $news->detail?->grouped_sources;

        if (is_array($grouped)) {
            foreach ($grouped as $source) {
                if (! is_array($source)) {
                    continue;
                }

                $gUrl = trim((string) ($source['url'] ?? ''));
                $gTitle = trim((string) ($source['title'] ?? 'Anime News Network'));

                if ($gUrl === '' || ! filter_var($gUrl, FILTER_VALIDATE_URL)) {
                    continue;
                }

                $items[] = sprintf(
                    '<li><a href="%s" rel="noopener noreferrer" target="_blank">%s</a></li>',
                    e($gUrl),
                    e('ANN: '.$gTitle),
                );
            }
        }

        if ($items === []) {
            return '';
        }

        return '<h2>Fuentes</h2><ul>'.implode('', $items).'</ul>';
    }

    private static function imageCreditBlock(News $news): string
    {
        $detail = $news->detail;
        $source = $detail?->featured_image_source;

        if ($source === 'generated') {
            return '<p class="esquinaanime-image-credit"><em>Imagen ilustrativa generada para EsquinaAnime.</em></p>';
        }

        if ($news->source === 'anime_news' && $source === 'scraped') {
            return '<p class="esquinaanime-image-credit"><em>Imagen promocional vía Anime News Network.</em></p>';
        }

        return '';
    }
}
