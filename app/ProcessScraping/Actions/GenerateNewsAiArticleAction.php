<?php

namespace App\ProcessScraping\Actions;

use App\Models\News;
use App\Models\NewsAiArticle;
use App\ProcessScraping\Ai\AiArticleResponseParser;
use App\ProcessScraping\Ai\OllamaClient;
use App\ProcessScraping\Ai\OllamaModelSelector;
use App\ProcessScraping\Prompts\ArticleGenerationPrompt;
use App\ProcessScraping\Prompts\ArticleTypeClassifier;
use App\ProcessScraping\Support\HtmlArticleSanitizer;
use App\ProcessScraping\Support\YoutubeExtractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateNewsAiArticleAction
{
    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly AiArticleResponseParser $parser,
        private readonly ArticleTypeClassifier $classifier,
        private readonly OllamaModelSelector $modelSelector,
    ) {}

    /**
     * @return array{processed: int, success: int, failed: int, news_ids: list<int>, errors: list<array{news_id: int, message: string}>}
     */
    public function execute(int $limit, bool $force, bool $includeRawHtml, ?array $newsIds = null): array
    {
        $processed = 0;
        $success = 0;
        $failed = 0;
        $successNewsIds = [];
        $errors = [];

        $items = $this->resolveItems($limit, $force, $newsIds);

        foreach ($items as $news) {
            $processed++;
            $detail = $news->detail;
            if ($detail === null || $detail->content_text === null) {
                $failed++;
                $news->update(['status_ia' => 'failed']);
                continue;
            }

            $rawHtml = $includeRawHtml ? ($detail->raw_html ?? null) : null;

            try {
                $articleType = $this->classifier->classify(
                    $news->title,
                    $detail->content_text,
                    $news->category,
                );

                $youtubeEmbeds = YoutubeExtractor::collect(
                    $detail->raw_html ?? null,
                    $detail->research_context,
                    $detail->research_raw,
                );
                $model = $this->modelSelector->forNews($news, $detail->content_text);
                $researchContext = $detail->research_context;

                $body = null;
                $parts = null;
                $maxAttempts = ($includeRawHtml && $rawHtml !== null) ? 5 : 3;

                for ($i = 0; $i < $maxAttempts; $i++) {
                    $promptRawHtml = ($i < 3) ? $rawHtml : null;

                    $prompt = ArticleGenerationPrompt::user(
                        $news->title,
                        $detail->content_text,
                        $promptRawHtml,
                        $news->source,
                        $youtubeEmbeds,
                        $articleType,
                        $researchContext,
                    );

                    if ($i > 0) {
                        $prompt .= "\n\nTu respuesta anterior no fue JSON válido o vino vacío. Responde SOLO con JSON puro con las claves title, excerpt y html. Sin ``` ni texto adicional.";
                    }

                    $body = $this->ollama->generate(
                        ArticleGenerationPrompt::system($articleType),
                        $prompt,
                        $model,
                    );

                    Log::info('news_ai: intento '.$i, [
                        'news_id' => $news->id,
                        'model' => $model,
                        'with_raw_html' => $promptRawHtml !== null,
                        'has_research' => $researchContext !== null,
                        'prompt_chars' => mb_strlen($prompt),
                        'response' => $body,
                    ]);

                    $parts = $this->parser->parse($body);

                    if (! empty($parts['body_html'])) {
                        break;
                    }
                }

                if (empty($parts['generated_title'])) {
                    $parts['generated_title'] = $news->title;
                }

                if (! empty($parts['excerpt'])) {
                    $parts['excerpt'] = trim($parts['excerpt']);

                    if (mb_strlen($parts['excerpt']) < 30) {
                        $parts['excerpt'] = null;
                    }
                }

                if (empty($parts['excerpt']) && ! empty($parts['body_html'])) {
                    $text = trim(strip_tags($parts['body_html']));
                    $excerpt = mb_substr($text, 0, 200);
                    $excerpt = preg_replace('/\s+\S*$/u', '', $excerpt);
                    $parts['excerpt'] = $excerpt;
                }

                if (empty($parts['body_html'])) {
                    throw new \RuntimeException('Respuesta sin HTML válido');
                }

                $parts['body_html'] = HtmlArticleSanitizer::sanitize($parts['body_html'], $youtubeEmbeds);
                $parts['body_html'] = HtmlArticleSanitizer::ensureYoutubeEmbed($parts['body_html'], $youtubeEmbeds);

                DB::transaction(function () use ($news, $parts, $articleType, $body, $model): void {
                    NewsAiArticle::updateOrCreate(
                        ['news_id' => $news->id],
                        [
                            'source_title' => $news->title,
                            'generated_title' => $parts['generated_title'],
                            'excerpt' => $parts['excerpt'],
                            'body_html' => $parts['body_html'],
                            'raw_ai_response' => $body,
                            'model' => $model,
                            'article_type' => $articleType,
                            'sent_wordpress' => false,
                            'sent_wordpress_at' => null,
                        ],
                    );
                    $news->update(['status_ia' => 'processed']);
                });

                $success++;
                $successNewsIds[] = $news->id;
            } catch (Throwable $e) {
                $failed++;
                $news->update(['status_ia' => 'failed']);
                $message = $e->getMessage();
                Log::warning('news_ai: fallo al generar artículo', [
                    'news_id' => $news->id,
                    'error' => $message,
                ]);
                $errors[] = ['news_id' => $news->id, 'message' => $message];
            }
        }

        return [
            'processed' => $processed,
            'success' => $success,
            'failed' => $failed,
            'news_ids' => $successNewsIds,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<int>|null  $newsIds
     * @return \Illuminate\Support\Collection<int, News>
     */
    private function resolveItems(int $limit, bool $force, ?array $newsIds)
    {
        $query = News::query()
            ->select('news.*')
            ->join('news_details as nd', 'nd.news_id', '=', 'news.id')
            ->whereNotNull('nd.raw_html')
            ->whereNotNull('nd.content_text')
            ->where('nd.status', 'processed')
            ->with('detail')
            ->orderBy('news.id');

        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('news.status_ia')
                    ->orWhere('news.status_ia', 'failed');
            });
        }

        if ($newsIds !== null) {
            if ($newsIds === []) {
                return collect();
            }

            return $query->whereIn('news.id', $newsIds)->get();
        }

        return $query->limit(max($limit, 1))->get();
    }
}
