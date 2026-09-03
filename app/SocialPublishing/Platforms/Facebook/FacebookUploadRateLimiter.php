<?php

namespace App\SocialPublishing\Platforms\Facebook;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Limita la velocidad de fragmentos y reintenta 429 de Meta Upload API.
 */
class FacebookUploadRateLimiter
{
    private const MIN_REQUEST_INTERVAL_US = 250_000;

    private const MAX_RATE_LIMIT_RETRIES = 12;

    private float $lastRequestAt = 0.0;

    /**
     * @param  callable(): Response  $request
     */
    public function send(callable $request, string $step, int $offset = 0): Response
    {
        $rateLimitAttempts = 0;

        while (true) {
            $this->waitForSlot();

            $response = $request();

            if ($response->status() === 429 || $this->isUploadRateLimited($response)) {
                $rateLimitAttempts++;

                if ($rateLimitAttempts > self::MAX_RATE_LIMIT_RETRIES) {
                    throw new RuntimeException(
                        "Facebook ({$step} en offset {$offset}): límite de subida superado tras {$rateLimitAttempts} reintentos.",
                    );
                }

                $backoffMs = $this->parseBackoffMs($response);
                $waitSeconds = (int) ceil($backoffMs / 1000);

                Log::warning('facebook: upload rate limited', [
                    'step' => $step,
                    'offset' => $offset,
                    'attempt' => $rateLimitAttempts,
                    'backoff_ms' => $backoffMs,
                    'status' => $response->status(),
                ]);

                sleep(max(1, $waitSeconds));

                continue;
            }

            FacebookGraphResponse::assertSuccessful($response, $step);

            return $response;
        }
    }

    private function waitForSlot(): void
    {
        if ($this->lastRequestAt <= 0) {
            $this->lastRequestAt = microtime(true);

            return;
        }

        $elapsedUs = (int) ((microtime(true) - $this->lastRequestAt) * 1_000_000);

        if ($elapsedUs < self::MIN_REQUEST_INTERVAL_US) {
            usleep(self::MIN_REQUEST_INTERVAL_US - $elapsedUs);
        }

        $this->lastRequestAt = microtime(true);
    }

    private function isUploadRateLimited(Response $response): bool
    {
        $type = $response->json('debug_info.type');

        return is_string($type) && $type === 'UploadRateLimitedError';
    }

    private function parseBackoffMs(Response $response): int
    {
        $backoff = $response->json('backoff');

        if (is_numeric($backoff) && (int) $backoff > 0) {
            return (int) $backoff;
        }

        return 60_000;
    }
}
