<?php

namespace App\ProcessScraping\Support;

class YoutubeExtractor
{
    public static function extract(?string $html): array
    {
        if (empty($html)) {
            return [];
        }

        $embeds = [];

        preg_match_all(
            '/<iframe[^>]+src=["\']([^"\']*youtube\.com\/embed\/[^"\']+)["\']/i',
            $html,
            $iframes
        );

        foreach ($iframes[1] as $src) {
            $clean = preg_replace('/[?&]si=[^&]+/', '', $src);
            $clean = rtrim($clean, '?&');
            $embeds[] = $clean;
        }

        preg_match_all(
            '/https?:\/\/(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w\-]{11})/i',
            $html,
            $links
        );

        foreach ($links[1] as $videoId) {
            $embedUrl = 'https://www.youtube.com/embed/'.$videoId;
            if (! in_array($embedUrl, $embeds)) {
                $embeds[] = $embedUrl;
            }
        }

        return $embeds;
    }
}
