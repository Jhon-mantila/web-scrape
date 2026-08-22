<?php

namespace App\ProcessScraping\Support;

class PromptContextLimiter
{
    public static function contentText(string $text, int $max = 12000): string
    {
        return self::truncate($text, $max, 'Contenido de referencia');
    }

    public static function rawHtml(?string $html, int $max = 4000): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }

        return self::truncate($html, $max, 'HTML crudo');
    }

    private static function truncate(string $text, int $max, string $label): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $max);
        $truncated = preg_replace('/\s+\S*$/u', '', $truncated);

        return $truncated."\n\n[{$label} truncado por límite de contexto de la IA.]";
    }
}
