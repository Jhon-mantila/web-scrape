<?php

namespace App\ProcessScraping;

class YoutubeExtractor
{
    public static function extract(?string $html): array
    {
        if (empty($html)) {
            return [];
        }

        preg_match_all(
            '/<iframe[^>]+src=["\']([^"\']+youtube\.com\/embed\/[^"\']+)["\']/i',
            $html,
            $matches
        );

        return $matches[1] ?? [];
    }
}