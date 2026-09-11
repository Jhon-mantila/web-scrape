<?php

namespace App\SocialPublishing\Platforms\Facebook;

class FacebookVideoPermalink
{
    private const FACEBOOK_ORIGIN = 'https://www.facebook.com';

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

        if (self::isFeedPostPermalink($apiPermalink)) {
            return self::absoluteFacebookUrl((string) $apiPermalink);
        }

        if (self::isPageVideoPermalink($apiPermalink)) {
            return self::absoluteFacebookUrl((string) $apiPermalink);
        }

        if ($pageId !== null && $pageId !== '') {
            return self::FACEBOOK_ORIGIN."/{$pageId}/videos/{$videoId}/";
        }

        return self::FACEBOOK_ORIGIN.'/watch?v='.$videoId;
    }

    private static function normalizeReelUrl(?string $apiPermalink, string $videoId): string
    {
        if (is_string($apiPermalink) && $apiPermalink !== '' && self::embedLooksLikeReel($apiPermalink)) {
            return self::absoluteFacebookUrl($apiPermalink);
        }

        return self::FACEBOOK_ORIGIN."/reel/{$videoId}/";
    }

    private static function isFeedPostPermalink(?string $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        return str_contains($url, '/posts/')
            || str_contains($url, '/permalink/')
            || str_contains($url, 'story_fbid=')
            || preg_match('#facebook\.com/\d+_\d+#', $url) === 1;
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

    private static function absoluteFacebookUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return self::FACEBOOK_ORIGIN.'/'.ltrim($url, '/');
    }
}
