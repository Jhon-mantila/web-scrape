<?php

namespace App\SocialPublishing\Support;

final class PlatformTokenFailureDetector
{
    public static function looksLikeTokenFailure(string $message): bool
    {
        $message = trim($message);

        if ($message === '') {
            return false;
        }

        $lower = mb_strtolower($message);

        $strong = [
            'token vencido',
            'token de linkedin vencido',
            'refresh token vencido',
            'invalid oauth',
            'invalid access token',
            'error validating access token',
            'session has expired',
            'the access token expired',
            'access token has expired',
            'oauth exception',
            'cannot parse access token',
            'revoked access token',
            'no se pudo refrescar token',
        ];

        foreach ($strong as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        if (preg_match('/\b190\b/', $lower) !== false && str_contains($lower, 'token')) {
            return true;
        }

        if (str_contains($lower, 'unauthorized') && str_contains($lower, 'token')) {
            return true;
        }

        return false;
    }
}
