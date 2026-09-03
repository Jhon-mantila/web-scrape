<?php

namespace App\SocialPublishing\Platforms\Facebook;

class FacebookVideoPermalink
{
    /**
     * Meta suele devolver permalink_url con /reel/ aunque el video sea horizontal de página.
     */
    public static function build(
        string $videoId,
        string $contentType,
        ?string $pageId = null,
        ?string $apiPermalink = null,
    ): string {
        if ($videoId === '') {
            return '';
        }

        if ($contentType === 'reel') {
            return self::normalizeReelUrl($apiPermalink, $videoId);
        }

        if (self::isPageVideoPermalink($apiPermalink)) {
            return (string) $apiPermalink;
        }

        if ($pageId !== null && $pageId !== '') {
            return "https://www.facebook.com/{$pageId}/videos/{$videoId}/";
        }

        return "https://www.facebook.com/watch?v={$videoId}";
    }

    private static function normalizeReelUrl(?string $apiPermalink, string $videoId): string
    {
        if (is_string($apiPermalink) && $apiPermalink !== '' && self::embedLooksLikeReel($apiPermalink)) {
            return $apiPermalink;
        }

        return "https://www.facebook.com/reel/{$videoId}/";
    }

    private static function isPageVideoPermalink(?string $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        if (self::embedLooksLikeReel($url)) {
            return false;
        }

        return str_contains($url, '/videos/')
            || str_contains($url, 'watch?v=')
            || str_contains($url, 'fb.watch/');
    }

    private static function embedLooksLikeReel(string $url): bool
    {
        $decoded = urldecode($url);

        return str_contains($url, '/reel/')
            || str_contains($decoded, '/reel/');
    }
}
