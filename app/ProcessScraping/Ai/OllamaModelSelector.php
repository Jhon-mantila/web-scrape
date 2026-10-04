<?php

namespace App\ProcessScraping\Ai;

use App\Models\News;
use App\ProcessScraping\Ai\Support\AiSettings;

class OllamaModelSelector
{
    public function forNews(News $news, string $contentText): string
    {
        $premium = AiSettings::premiumModel();
        $default = AiSettings::defaultModel();

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
}
