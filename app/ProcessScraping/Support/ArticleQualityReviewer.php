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

    public function validateGeneratedTitle(?string $title): ?string
    {
        if ($title === null || trim($title) === '') {
            return null;
        }

        $trimmed = trim($title);

        if ($this->looksLikeEnglishHeadline($trimmed)) {
            return 'El título generado está en inglés; debe redactarse en español (SEO).';
        }

        if (mb_strlen($trimmed) > 70) {
            return 'El título SEO supera 70 caracteres ('.mb_strlen($trimmed).').';
        }

        $leak = GeneratedArticleLeakGuard::findLeak($trimmed);

        if ($leak !== null) {
            return 'El título contiene referencias internas del sistema ('.$leak.').';
        }

        return null;
    }

    /** Título apto como referencia SEO (español, no inglés, ≤70 caracteres). */
    public function isAcceptableSpanishSeoReference(?string $title): bool
    {
        if ($this->validateGeneratedTitle($title) !== null) {
            return false;
        }

        $trimmed = trim((string) $title);

        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/[áéíóúñ¿¡]/u', $trimmed)) {
            return true;
        }

        $lower = mb_strtolower($trimmed);
        $spanishHints = [
            ' de ',
            ' la ',
            ' el ',
            ' en ',
            ' para ',
            ' del ',
            ' y ',
            'anime',
            'manga',
            'temporada',
            'película',
            'pelicula',
            'estreno',
            'oricon',
            'novela',
        ];

        foreach ($spanishHints as $hint) {
            if (str_contains($lower, $hint)) {
                return true;
            }
        }

        return false;
    }

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

        $leak = GeneratedArticleLeakGuard::findLeak($html);

        if ($leak !== null) {
            return 'El artículo menciona términos internos ('.$leak.'); redacta solo para lectores.';
        }

        return null;
    }

    private function looksLikeEnglishHeadline(string $title): bool
    {
        $lower = mb_strtolower($title);

        $englishPhrases = [
            'where to watch',
            'how to watch',
            'according to',
            'light novel ranking',
            ' tops ',
            ' ranking for ',
        ];

        foreach ($englishPhrases as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        $hasSpanishMarks = (bool) preg_match('/[áéíóúñ¿¡]/u', $title);

        if ($hasSpanishMarks) {
            return false;
        }

        return (bool) preg_match('/\b(the|and|for|with|season|episode|watch|ranking|company)\b/i', $title);
    }
}
