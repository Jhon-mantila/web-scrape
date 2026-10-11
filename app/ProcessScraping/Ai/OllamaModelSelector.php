<?php

namespace App\ProcessScraping\Ai;

use App\Models\News;
use App\ProcessScraping\Ai\Support\AiSettings;
use App\ProcessScraping\Support\ScraperArticleAiContext;
use App\SocialPublishing\Enums\VideoCaptionAiProvider;
use App\SocialPublishing\Support\VideoCaptionAiSettings;

class OllamaModelSelector
{
    public function forNews(News $news, string $contentText): string
    {
        $explicit = ScraperArticleAiContext::articleModel();

        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        $provider = ScraperArticleAiContext::get();

        $premium = $this->premiumModelFor($provider);
        $default = VideoCaptionAiSettings::defaultModel($provider);

        if ($premium === null) {
            return $default;
        }

        $minLength = AiSettings::premiumMinChars();

        if ($news->source === 'anime_news' && mb_strlen($contentText) >= $minLength) {
            return $premium;
        }

        if (mb_strlen($contentText) >= ($minLength * 2)) {
            return $premium;
        }

        return $default;
    }

    private function premiumModelFor(VideoCaptionAiProvider $provider): ?string
    {
        $premium = match ($provider) {
            VideoCaptionAiProvider::Deepseek => config('services.deepseek.model_premium'),
            default => config('services.ollama.model_premium'),
        };

        $premium = is_string($premium) ? trim($premium) : '';

        return $premium !== '' ? $premium : null;
    }
}
