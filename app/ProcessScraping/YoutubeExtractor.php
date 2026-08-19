<?php

namespace App\ProcessScraping;

class YoutubeExtractor
{
    public static function extract(?string $html): array
    {
        if (empty($html)) {
            return [];
        }

        $embeds = [];

        // iframes ya embedidos
        preg_match_all(
            '/<iframe[^>]+src=["\']([^"\']*youtube\.com\/embed\/[^"\']+)["\']/i',
            $html,
            $iframes
        );

        foreach ($iframes[1] as $src) {
            // limpiar parámetros de tracking como ?si=...
            $clean = preg_replace('/[?&]si=[^&]+/', '', $src);
            $clean = rtrim($clean, '?&');
            $embeds[] = $clean;
        }

        // links de youtube sueltos (watch?v= o youtu.be/)
        preg_match_all(
            '/https?:\/\/(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w\-]{11})/i',
            $html,
            $links
        );

        foreach ($links[1] as $videoId) {
            $embedUrl = 'https://www.youtube.com/embed/' . $videoId;
            if (!in_array($embedUrl, $embeds)) {
                $embeds[] = $embedUrl;
            }
        }

        return $embeds;
    }
}