<?php

namespace App\ProcessScraping\Support;

class ArticleQualityReviewer
{
    /** @var list<string> */
    private const FORBIDDEN_SNIPPETS = [
        '¿qué opinas',
        'comparte en los comentarios',
        'en el mundo del anime',
        'sin duda',
        'emocionante noticia',
        'en conclusión',
    ];

    public function validateHtml(string $html, string $estado): ?string
    {
        if (mb_strtoupper(trim($estado)) === 'INSUFICIENTE') {
            return 'La IA marcó el material como INSUFICIENTE para publicar.';
        }

        $wordCount = ArticleWordCounter::fromHtml($html);
        $text = mb_strtolower(trim(strip_tags($html)));
        $minWords = (int) config('services.scraper.anime_news_min_words', 350);

        if ($wordCount < $minWords) {
            return "Artículo demasiado corto ({$wordCount} palabras; mínimo {$minWords}).";
        }

        foreach (self::FORBIDDEN_SNIPPETS as $snippet) {
            if (str_contains($text, $snippet)) {
                return 'El artículo contiene frases prohibidas del estilo plantilla.';
            }
        }

        return null;
    }
}
