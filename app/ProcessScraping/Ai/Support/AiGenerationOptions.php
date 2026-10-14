<?php

namespace App\ProcessScraping\Ai\Support;

final class AiGenerationOptions
{
    public function __construct(
        public readonly ?float $temperature = null,
        public readonly ?bool $formatJson = null,
    ) {}

    public static function forFactSheet(): self
    {
        return new self(
            temperature: (float) config('services.scraper.anime_news_fact_sheet_temperature', 0.15),
            formatJson: true,
        );
    }

    public static function forEntityExtraction(): self
    {
        return new self(
            temperature: (float) config('services.scraper.anime_news_entity_temperature', 0.15),
            formatJson: true,
        );
    }

    public static function forArticleFromFactSheet(): self
    {
        return new self(
            temperature: (float) config('services.scraper.anime_news_write_temperature', 0.65),
            formatJson: true,
        );
    }
}
