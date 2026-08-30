<?php

namespace App\SocialPublishing\Platforms\LinkedIn;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class LinkedInHttpClient
{
    public static function api(array $headers): PendingRequest
    {
        return self::base()
            ->withHeaders($headers);
    }

    public static function upload(): PendingRequest
    {
        return self::base();
    }

    public static function oauthForm(): PendingRequest
    {
        return self::base()->asForm();
    }

    private static function base(): PendingRequest
    {
        return Http::timeout(600)
            ->connectTimeout(60)
            ->retry(5, 3000, fn (\Throwable $exception) => self::shouldRetry($exception));
    }

    private static function shouldRetry(\Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'Could not resolve host')
            || str_contains($message, 'cURL error 6')
            || str_contains($message, 'cURL error 7')
            || str_contains($message, 'Connection timed out')
            || str_contains($message, 'Resolving timed out');
    }
}
