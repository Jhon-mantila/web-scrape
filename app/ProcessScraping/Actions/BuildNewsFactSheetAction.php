<?php

namespace App\ProcessScraping\Actions;

use App\Models\News;
use App\ProcessScraping\Ai\Support\AiGenerationOptions;
use App\ProcessScraping\Ai\Support\JsonPayloadParser;
use App\ProcessScraping\Ai\Contracts\TextGenerationClient;
use App\ProcessScraping\Prompts\FactSheetPrompt;
use App\ProcessScraping\Support\GroupedSourcesFormatter;
use App\ProcessScraping\Support\ScraperArticleAiContext;
use App\SocialPublishing\Support\VideoCaptionAiClientFactory;
use Illuminate\Support\Facades\Log;

class BuildNewsFactSheetAction
{
    public function __construct(
        private readonly VideoCaptionAiClientFactory $clientFactory,
        private readonly JsonPayloadParser $jsonParser,
    ) {}

    private function llm(): TextGenerationClient
    {
        return $this->clientFactory->client(ScraperArticleAiContext::get());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(News $news, ?string $model = null, bool $persist = true): ?array
    {
        $detail = $news->detail;

        if ($detail === null || $detail->content_text === null) {
            return null;
        }

        $groupedBlock = GroupedSourcesFormatter::toPromptBlock(
            is_array($detail->grouped_sources) ? $detail->grouped_sources : null,
        );

        $raw = $this->llm()->generate(
            FactSheetPrompt::system(),
            FactSheetPrompt::user(
                $news->title,
                (string) $news->url,
                $detail->content_text,
                $detail->research_context,
                $groupedBlock,
            ),
            $model,
            AiGenerationOptions::forFactSheet(),
        );

        $sheet = $this->jsonParser->parse($raw);

        if ($sheet === null) {
            Log::warning('fact_sheet: JSON inválido', ['news_id' => $news->id]);

            return null;
        }

        if ($persist) {
            $detail->update(['fact_sheet' => $sheet]);
        }

        return $sheet;
    }
}
