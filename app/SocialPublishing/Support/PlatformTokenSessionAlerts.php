<?php

namespace App\SocialPublishing\Support;

use App\SocialPublishing\Enums\Platform;

final class PlatformTokenSessionAlerts
{
    private const SESSION_KEY = 'platform_token_alerts';

    public static function register(string $platformKey, string $message): void
    {
        if (! PlatformTokenFailureDetector::looksLikeTokenFailure($message)) {
            return;
        }

        $label = Platform::tryFrom($platformKey)?->label() ?? $platformKey;

        $alerts = session(self::SESSION_KEY, []);
        $alerts[$platformKey] = [
            'platform' => $platformKey,
            'label' => $label,
            'message' => mb_substr(trim($message), 0, 280),
            'detected_at' => now()->toIso8601String(),
            'source' => 'api_error',
        ];

        session([self::SESSION_KEY => $alerts]);
    }

    public static function clear(string $platformKey): void
    {
        $alerts = session(self::SESSION_KEY, []);

        if (! array_key_exists($platformKey, $alerts)) {
            return;
        }

        unset($alerts[$platformKey]);
        session([self::SESSION_KEY => $alerts]);
    }

    /**
     * @return array<string, array{platform: string, label: string, message: string, detected_at?: string, source: string}>
     */
    public static function all(): array
    {
        $alerts = session(self::SESSION_KEY, []);

        return is_array($alerts) ? $alerts : [];
    }
}
