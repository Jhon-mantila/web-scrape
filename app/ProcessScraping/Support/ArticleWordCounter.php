<?php

namespace App\ProcessScraping\Support;

class ArticleWordCounter
{
    public static function fromHtml(?string $html): int
    {
        $text = trim(strip_tags($html ?? ''));

        if ($text === '') {
            return 0;
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? count($words) : 0;
    }
}
