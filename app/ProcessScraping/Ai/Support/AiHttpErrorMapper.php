<?php

namespace App\ProcessScraping\Ai\Support;

use App\ProcessScraping\Ai\Exceptions\AiProviderException;

class AiHttpErrorMapper
{
    public static function fromResponse(string $provider, int $status, string $body, string $contextUrl): AiProviderException
    {
        $lower = mb_strtolower($body);
        $kind = AiProviderException::KIND_HTTP;
        $snippet = $body !== '' ? mb_substr($body, 0, 500) : '';

        if ($status === 401 || $status === 403) {
            $kind = AiProviderException::KIND_AUTH;
        } elseif ($status === 402 || self::looksLikeQuota($lower)) {
            $kind = AiProviderException::KIND_QUOTA;
        } elseif ($status === 429 || str_contains($lower, 'rate limit')) {
            $kind = AiProviderException::KIND_RATE_LIMIT;
        }

        return new AiProviderException(
            "HTTP {$status} en {$contextUrl}.{$snippet}",
            $provider,
            $kind,
            $status,
        );
    }

    public static function connection(string $provider, string $contextUrl, string $detail): AiProviderException
    {
        return new AiProviderException(
            "No se pudo conectar con {$contextUrl}: {$detail}",
            $provider,
            AiProviderException::KIND_CONNECTION,
        );
    }

    private static function looksLikeQuota(string $lowerBody): bool
    {
        foreach ([
            'insufficient balance',
            'insufficient quota',
            'quota exceeded',
            'exceeded your current quota',
            'billing',
            'credit balance',
            'payment required',
            'out of credits',
            'balance is insufficient',
        ] as $needle) {
            if (str_contains($lowerBody, $needle)) {
                return true;
            }
        }

        return false;
    }

}
