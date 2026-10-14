<?php

namespace App\SocialPublishing\Support;

final class RegisterPlatformTokenFailure
{
    public static function fromPlatform(string $platformKey, ?string $errorMessage): void
    {
        if ($errorMessage === null || $errorMessage === '') {
            return;
        }

        PlatformTokenSessionAlerts::register($platformKey, $errorMessage);
    }
}
