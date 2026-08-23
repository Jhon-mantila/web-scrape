<?php

namespace App\ProcessScraping\Ai;

use App\Models\News;

class OllamaModelSelector
{
    public function forNews(News $news, string $contentText): string
    {
        $premium = config('services.ollama.model_premium');
        $default = config('services.ollama.model');

        if ($premium === null || $premium === '') {
            return $default;
        }

        $minLength = (int) config('services.ollama.premium_min_chars', 4500);

        if ($news->source === 'anime_news' && mb_strlen($contentText) >= $minLength) {
            return $premium;
        }

        if (mb_strlen($contentText) >= ($minLength * 2)) {
            return $premium;
        }

        return $default;
    }
}
