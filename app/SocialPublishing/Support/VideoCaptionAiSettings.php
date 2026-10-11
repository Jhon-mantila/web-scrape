<?php

namespace App\SocialPublishing\Support;

use App\SocialPublishing\Enums\VideoCaptionAiProvider;

class VideoCaptionAiSettings
{
    public static function defaultModel(VideoCaptionAiProvider|string $provider): string
    {
        $provider = $provider instanceof VideoCaptionAiProvider
            ? $provider
            : VideoCaptionAiProvider::tryFromStored(is_string($provider) ? $provider : null);

        return match ($provider) {
            VideoCaptionAiProvider::Deepseek => (string) config('services.deepseek.model'),
            default => (string) config('services.ollama.model'),
        };
    }

    public static function batchesCaptions(VideoCaptionAiProvider|string $provider): bool
    {
        $provider = $provider instanceof VideoCaptionAiProvider
            ? $provider
            : VideoCaptionAiProvider::tryFromStored(is_string($provider) ? $provider : null);

        return $provider === VideoCaptionAiProvider::Deepseek;
    }

    public static function resolveConfiguredModel(?string $configured, VideoCaptionAiProvider|string $provider): string
    {
        $configured = trim((string) ($configured ?? ''));
        $provider = $provider instanceof VideoCaptionAiProvider
            ? $provider
            : VideoCaptionAiProvider::tryFromStored(is_string($provider) ? $provider : null);

        if ($configured === '') {
            return self::defaultModel($provider);
        }

        if ($provider === VideoCaptionAiProvider::Deepseek && ! str_starts_with(strtolower($configured), 'deepseek')) {
            return self::defaultModel($provider);
        }

        if ($provider === VideoCaptionAiProvider::OllamaLocal && str_starts_with(strtolower($configured), 'deepseek')) {
            return self::defaultModel($provider);
        }

        return $configured;
    }
}
