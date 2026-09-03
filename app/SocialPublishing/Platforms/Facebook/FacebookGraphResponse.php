<?php

namespace App\SocialPublishing\Platforms\Facebook;

use Illuminate\Http\Client\Response;
use RuntimeException;

class FacebookGraphResponse
{
    public static function assertSuccessful(Response $response, string $step): void
    {
        $json = $response->json();

        if (is_array($json) && isset($json['error']) && is_array($json['error'])) {
            $message = $json['error']['message'] ?? 'Error desconocido';
            $code = $json['error']['code'] ?? '?';

            throw new RuntimeException("Facebook ({$step}): [{$code}] {$message}");
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "Facebook ({$step}): {$response->status()} — {$response->body()}",
            );
        }
    }
}
