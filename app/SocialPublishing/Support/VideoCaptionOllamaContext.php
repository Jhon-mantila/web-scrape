<?php

namespace App\SocialPublishing\Support;

use App\Models\SocialVideo;
use App\ProcessScraping\Ai\Support\OllamaConnectivity;
use App\SocialPublishing\Enums\VideoCaptionAiProvider;

final class VideoCaptionOllamaContext
{
    private static bool $active = false;

    private static string $target = OllamaConnectivity::TARGET_LOCAL;

    public static function applyFromVideo(SocialVideo $video): void
    {
        self::$active = $video->captionAiProvider() === VideoCaptionAiProvider::OllamaLocal;

        $target = (string) ($video->caption_ollama_target ?? OllamaConnectivity::TARGET_LOCAL);

        self::$target = in_array($target, [OllamaConnectivity::TARGET_LOCAL, OllamaConnectivity::TARGET_REMOTE], true)
            ? $target
            : OllamaConnectivity::TARGET_LOCAL;
    }

    public static function usesVideoOllamaRouting(): bool
    {
        return self::$active;
    }

    public static function ollamaTarget(): string
    {
        return self::$target;
    }

    public static function reset(): void
    {
        self::$active = false;
        self::$target = OllamaConnectivity::TARGET_LOCAL;
    }
}
