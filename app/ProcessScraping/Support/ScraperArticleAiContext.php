<?php

namespace App\ProcessScraping\Support;

use App\ProcessScraping\Ai\Support\AiSettings;
use App\ProcessScraping\Ai\Support\OllamaConnectivity;
use App\SocialPublishing\Enums\VideoCaptionAiProvider;

final class ScraperArticleAiContext
{
    private static VideoCaptionAiProvider $provider = VideoCaptionAiProvider::OllamaLocal;

    private static string $ollamaTarget = OllamaConnectivity::TARGET_LOCAL;

    private static ?string $articleModel = null;

    private static bool $scraperOllamaRouting = false;

    public static function set(VideoCaptionAiProvider|string|null $provider): void
    {
        if ($provider === null || $provider === '') {
            self::$provider = VideoCaptionAiProvider::OllamaLocal;

            return;
        }

        self::$provider = $provider instanceof VideoCaptionAiProvider
            ? $provider
            : VideoCaptionAiProvider::tryFromStored($provider);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function applyFromPipelineOptions(array $options): void
    {
        self::set($options['article_ai_provider'] ?? VideoCaptionAiProvider::OllamaLocal->value);

        $target = (string) ($options['article_ollama_target'] ?? config('services.scraper.article_ollama_target', 'local'));

        self::$ollamaTarget = in_array($target, [OllamaConnectivity::TARGET_LOCAL, OllamaConnectivity::TARGET_REMOTE], true)
            ? $target
            : OllamaConnectivity::TARGET_LOCAL;

        $model = trim((string) ($options['article_ai_model'] ?? ''));

        self::$articleModel = $model !== '' ? $model : null;

        self::$scraperOllamaRouting = true;
    }

    public static function get(): VideoCaptionAiProvider
    {
        return self::$provider;
    }

    public static function ollamaTarget(): string
    {
        return self::$ollamaTarget;
    }

    public static function articleModel(): ?string
    {
        return self::$articleModel;
    }

    public static function usesScraperOllamaRouting(): bool
    {
        return self::$scraperOllamaRouting;
    }

    public static function usesAi(): bool
    {
        return self::get() !== VideoCaptionAiProvider::None;
    }

    public static function reset(): void
    {
        self::$provider = VideoCaptionAiProvider::OllamaLocal;
        self::$ollamaTarget = (string) config('services.scraper.article_ollama_target', OllamaConnectivity::TARGET_LOCAL);
        self::$articleModel = null;
        self::$scraperOllamaRouting = false;
    }

    /** Consola Artisan: respeta AI_PROVIDER global (deepseek u ollama_local). */
    public static function applyGlobalAiProviderFallback(): void
    {
        self::reset();

        self::set(
            AiSettings::provider() === 'deepseek'
                ? VideoCaptionAiProvider::Deepseek
                : VideoCaptionAiProvider::OllamaLocal,
        );
    }
}
