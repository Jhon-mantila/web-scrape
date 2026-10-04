<?php

namespace App\ProcessScraping\Ai\Support;

class AiSettings
{
    public static function provider(): string
    {
        $provider = strtolower((string) config('services.ai.provider', 'ollama_local'));

        return in_array($provider, ['ollama_local', 'deepseek', 'ollama_api'], true)
            ? $provider
            : 'ollama_local';
    }

    public static function defaultModel(): string
    {
        return (string) match (self::provider()) {
            'deepseek' => config('services.deepseek.model'),
            'ollama_api' => config('services.ollama_api.model'),
            default => config('services.ollama.model'),
        };
    }

    public static function premiumModel(): ?string
    {
        $premium = match (self::provider()) {
            'deepseek' => config('services.deepseek.model_premium'),
            'ollama_api' => config('services.ollama_api.model_premium'),
            default => config('services.ollama.model_premium'),
        };

        $premium = is_string($premium) ? trim($premium) : '';

        return $premium !== '' ? $premium : null;
    }

    public static function premiumMinChars(): int
    {
        return (int) config('services.ai.premium_min_chars', 4500);
    }

    public static function timeout(): int
    {
        return (int) config('services.ai.timeout', 600);
    }

    public static function formatJson(): bool
    {
        return (bool) config('services.ai.format_json', true);
    }

    public static function temperature(): float
    {
        return (float) config('services.ai.temperature', 0.75);
    }

    public static function maxTokens(): int
    {
        return (int) config('services.ai.max_tokens', 6144);
    }

    public static function shouldUnloadAfterGenerate(): bool
    {
        if (self::provider() !== 'ollama_local') {
            return false;
        }

        return (bool) config('services.ollama.unload_after_generate', true);
    }
}
