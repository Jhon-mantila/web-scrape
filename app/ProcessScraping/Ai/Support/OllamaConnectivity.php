<?php

namespace App\ProcessScraping\Ai\Support;

use App\ProcessScraping\Support\ScraperArticleAiContext;
use App\SocialPublishing\Enums\VideoCaptionAiProvider;
use App\SocialPublishing\Support\VideoCaptionOllamaContext;

final class OllamaConnectivity
{
    public const TARGET_LOCAL = 'local';

    public const TARGET_REMOTE = 'remote';

    public static function baseUrlForTarget(string $target): string
    {
        return match ($target) {
            self::TARGET_REMOTE => rtrim((string) config('services.ollama.remote_url'), '/'),
            default => rtrim((string) config('services.ollama.url'), '/'),
        };
    }

    public static function resolveBaseUrl(): string
    {
        if (
            ScraperArticleAiContext::get() === VideoCaptionAiProvider::OllamaLocal
            && ScraperArticleAiContext::usesScraperOllamaRouting()
        ) {
            return self::baseUrlForTarget(ScraperArticleAiContext::ollamaTarget());
        }

        if (VideoCaptionOllamaContext::usesVideoOllamaRouting()) {
            return self::baseUrlForTarget(VideoCaptionOllamaContext::ollamaTarget());
        }

        return self::baseUrlForTarget(self::TARGET_LOCAL);
    }

    public static function isRemoteActive(): bool
    {
        return self::resolveBaseUrl() === self::baseUrlForTarget(self::TARGET_REMOTE);
    }

    public static function remoteConfigured(): bool
    {
        return filled(config('services.ollama.remote_url'));
    }
}
