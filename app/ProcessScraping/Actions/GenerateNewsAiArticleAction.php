<?php

namespace App\ProcessScraping\Actions;

use App\Models\News;
use App\Models\NewsAiArticle;
use App\ProcessScraping\Ai\AiArticleResponseParser;
use App\ProcessScraping\Ai\FactSheetArticleResponseParser;
use App\ProcessScraping\Actions\DownloadFeaturedImagesAction;
use App\ProcessScraping\Ai\Contracts\TextGenerationClient;
use App\ProcessScraping\Ai\Exceptions\AiProviderException;
use App\ProcessScraping\Ai\OllamaModelSelector;
use App\ProcessScraping\Ai\Support\AiGenerationOptions;
use App\ProcessScraping\Support\ArticleQualityReviewer;
use App\ProcessScraping\Support\ArticleQualitySkipException;
use App\ProcessScraping\Support\ScraperArticleAiContext;
use App\SocialPublishing\Support\VideoCaptionAiClientFactory;
use App\ProcessScraping\Prompts\ArticleGenerationPrompt;
use App\ProcessScraping\Prompts\ArticleTypeClassifier;
use App\ProcessScraping\Prompts\FactSheetArticlePrompt;
use App\ProcessScraping\Support\HtmlArticleSanitizer;
use App\ProcessScraping\Support\YoutubeExtractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateNewsAiArticleAction
{
    public function __construct(
        private readonly VideoCaptionAiClientFactory $clientFactory,
        private readonly AiArticleResponseParser $parser,
        private readonly FactSheetArticleResponseParser $factSheetArticleParser,
        private readonly ArticleTypeClassifier $classifier,
        private readonly OllamaModelSelector $modelSelector,
        private readonly BuildNewsFactSheetAction $factSheetBuilder,
        private readonly ArticleQualityReviewer $qualityReviewer,
        private readonly DownloadFeaturedImagesAction $featuredImages,
        private readonly ResearchNewsAction $researchNews,
    ) {}

    private function llm(): TextGenerationClient
    {
        return $this->clientFactory->client(ScraperArticleAiContext::get());
    }

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

        $targetSuccess = max(1, $limit);
        $maxTries = max(
            $targetSuccess * (int) config('services.scraper.anime_news_max_tries_per_limit', 20),
            $targetSuccess,
        );
        /** @var list<int> $triedIds */
        $triedIds = [];
        $skippedQuality = 0;

        while ($success < $targetSuccess && count($triedIds) < $maxTries) {
            $news = $this->resolveNextCandidate($force, $newsIds, $triedIds);

            if ($news === null) {
                break;
            }

            $triedIds[] = $news->id;
            $processed++;
            $detail = $news->detail;

            $this->featuredImages->ensureFeaturedImageReady($news);
            $news->refresh();
            $news->load('detail');
            $detail = $news->detail;

            $this->ensureResearchForStagedPipeline($news, $force);

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

                if ($news->source === 'anime_news' && config('services.scraper.anime_news_staged')) {
                    $staged = $this->generateAnimeNewsStaged($news, $articleType, $model, $force, $youtubeEmbeds);
                    $parts = $staged['parts'];
                    $body = $staged['raw'];
                    $generatedTags = $staged['tags'];
                } else {
                    $generatedTags = [];
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

                    $body = $this->llm()->generate(
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

                DB::transaction(function () use ($news, $parts, $articleType, $body, $model, $generatedTags): void {
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
                            'generated_tags' => $generatedTags !== [] ? $generatedTags : null,
                            'sent_wordpress' => false,
                            'sent_wordpress_at' => null,
                            'wordpress_post_id' => null,
                            'wordpress_status' => null,
                            'wordpress_scheduled_at' => null,
                            'wordpress_url' => null,
                            'wordpress_author' => null,
                        ],
                    );
                    $news->update(['status_ia' => 'processed']);
                });

                $success++;
                $successNewsIds[] = $news->id;
            } catch (ArticleQualitySkipException $e) {
                $skippedQuality++;

                if ($e->isTooShort) {
                    $this->featuredImages->removeFeaturedImage($news);
                    $news->update(['status_ia' => 'skipped_short']);
                    Log::info('news_ai: artículo corto; imagen eliminada, se prueba otra noticia', [
                        'news_id' => $news->id,
                        'error' => $e->getMessage(),
                        'success' => $success,
                        'target' => $targetSuccess,
                    ]);
                } else {
                    $news->update(['status_ia' => 'skipped_quality']);
                    Log::info('news_ai: omitida por calidad, se prueba otra noticia', [
                        'news_id' => $news->id,
                        'error' => $e->getMessage(),
                        'success' => $success,
                        'target' => $targetSuccess,
                    ]);
                }

                $errors[] = ['news_id' => $news->id, 'message' => $e->getMessage()];
            } catch (AiProviderException $e) {
                $failed++;
                $news->update(['status_ia' => 'failed']);
                $message = $e->userMessage();
                Log::warning('news_ai: fallo proveedor IA', [
                    'news_id' => $news->id,
                    'provider' => $e->provider,
                    'kind' => $e->kind,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = ['news_id' => $news->id, 'message' => $message];
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

            if ($success >= $targetSuccess) {
                break;
            }
        }

        if ($success < $targetSuccess) {
            Log::warning('news_ai: no se alcanzó el objetivo de artículos generados', [
                'target' => $targetSuccess,
                'success' => $success,
                'skipped_quality' => $skippedQuality,
                'tried' => count($triedIds),
            ]);
        }

        return [
            'processed' => $processed,
            'success' => $success,
            'failed' => $failed,
            'skipped_quality' => $skippedQuality,
            'news_ids' => $successNewsIds,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<int>  $triedIds
     * @param  list<int>|null  $preferIds
     */
    private function resolveNextCandidate(bool $force, ?array $preferIds, array $triedIds): ?News
    {
        if ($preferIds !== null && $preferIds !== []) {
            $remaining = array_values(array_diff($preferIds, $triedIds));

            if ($remaining !== []) {
                $fromBatch = $this->eligibleNewsQuery($force)
                    ->whereIn('news.id', $remaining)
                    ->orderByDesc('news.id')
                    ->first();

                if ($fromBatch !== null) {
                    return $fromBatch;
                }
            }
        }

        $query = $this->eligibleNewsQuery($force)->orderByDesc('news.id');

        if ($triedIds !== []) {
            $query->whereNotIn('news.id', $triedIds);
        }

        return $query->first();
    }

    private function eligibleNewsQuery(bool $force): \Illuminate\Database\Eloquent\Builder
    {
        $query = News::query()
            ->select('news.*')
            ->join('news_details as nd', 'nd.news_id', '=', 'news.id')
            ->whereNotNull('nd.raw_html')
            ->whereNotNull('nd.content_text')
            ->where('nd.status', 'processed')
            ->whereNull('news.merged_into_news_id')
            ->where(function ($q) use ($force) {
                $blocked = ['merged', 'skipped_low_interest', 'skipped_short'];

                if (! $force) {
                    $blocked[] = 'skipped_quality';
                }

                $q->whereNull('news.status_ia')
                    ->orWhereNotIn('news.status_ia', $blocked);
            })
            ->with('detail');

        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('news.status_ia')
                    ->orWhere('news.status_ia', 'failed');
            });
        } else {
            $query->where(function ($q) {
                $q->whereNull('news.status_ia')
                    ->orWhereIn('news.status_ia', ['failed', 'skipped_quality', 'processed']);
            });
        }

        return $query;
    }

    /**
     * @param  list<string>  $youtubeEmbeds
     * @return array{parts: array{generated_title: ?string, excerpt: ?string, body_html: ?string}, raw: string, tags: list<string>}
     */
    private function generateAnimeNewsStaged(
        News $news,
        string $articleType,
        ?string $model,
        bool $force,
        array $youtubeEmbeds,
    ): array {
        $detail = $news->detail;

        if ($detail === null) {
            throw new \RuntimeException('Detalle de noticia ausente');
        }

        if ($detail->research_context === null || trim($detail->research_context) === '') {
            Log::warning('news_ai: generación por ficha sin investigación previa', ['news_id' => $news->id]);
        }

        $factSheet = $detail->fact_sheet;

        if ($force || ! is_array($factSheet) || $factSheet === []) {
            $factSheet = $this->factSheetBuilder->build($news, $model, true);
        }

        if (! is_array($factSheet) || $factSheet === []) {
            throw new \RuntimeException('No se pudo construir la ficha de hechos');
        }

        $sheetEstado = mb_strtoupper(trim((string) ($factSheet['estado'] ?? '')));

        if ($sheetEstado === 'INSUFICIENTE') {
            throw ArticleQualitySkipException::fromMessage(
                'Ficha de hechos INSUFICIENTE: material escaso para artículo sin inventar',
            );
        }

        $factSheetJson = json_encode($factSheet, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $body = '';
        $parsed = null;

        $minWords = (int) config('services.scraper.anime_news_min_words', 350);

        for ($i = 0; $i < 4; $i++) {
            $prompt = FactSheetArticlePrompt::user($factSheetJson, $articleType);

            if ($i > 0) {
                $prompt .= "\n\nResponde SOLO JSON válido con titulo, meta_descripcion, html, etiquetas, estado.";
            }

            if ($i >= 2) {
                $prompt .= "\n\nIMPORTANTE: el HTML debe superar {$minWords} palabras visibles. Desarrolla cada sección con más detalle.";
            }

            $body = $this->llm()->generate(
                FactSheetArticlePrompt::system($articleType),
                $prompt,
                $model,
                AiGenerationOptions::forArticleFromFactSheet(),
            );

            $parsed = $this->factSheetArticleParser->parse($body);

            if (empty($parsed['body_html'])) {
                continue;
            }

            $html = HtmlArticleSanitizer::sanitize($parsed['body_html'], $youtubeEmbeds);
            $html = HtmlArticleSanitizer::ensureYoutubeEmbed($html, $youtubeEmbeds);
            $estado = $parsed['estado'] ?? 'OK';
            $qualityError = $this->qualityReviewer->validateHtml($html, (string) $estado);

            if ($qualityError === null) {
                $parsed['body_html'] = $html;

                break;
            }

            if ($i < 3 && str_contains(mb_strtolower($qualityError), 'demasiado corto')) {
                continue;
            }

            if (str_contains(mb_strtolower($qualityError), 'demasiado corto')) {
                throw ArticleQualitySkipException::tooShort($qualityError);
            }

            throw ArticleQualitySkipException::fromMessage($qualityError);
        }

        if ($parsed === null || empty($parsed['body_html'])) {
            throw new \RuntimeException('Respuesta sin HTML válido (pipeline por ficha)');
        }

        $html = $parsed['body_html'];
        $title = $parsed['generated_title'] ?? $news->title;
        $excerpt = $parsed['excerpt'] ?? null;

        if ($excerpt !== null && mb_strlen(trim($excerpt)) < 30) {
            $excerpt = null;
        }

        return [
            'parts' => [
                'generated_title' => $title,
                'excerpt' => $excerpt,
                'body_html' => $html,
            ],
            'raw' => $body,
            'tags' => $parsed['tags'],
        ];
    }

    private function ensureResearchForStagedPipeline(News $news, bool $force): void
    {
        if ($news->source !== 'anime_news' || ! config('services.scraper.anime_news_staged')) {
            return;
        }

        if (! config('services.searxng.enabled')) {
            return;
        }

        $detail = $news->detail;

        if ($detail === null || $detail->content_text === null) {
            return;
        }

        $hasResearch = $detail->research_context !== null && trim($detail->research_context) !== '';

        if ($hasResearch && ! $force) {
            return;
        }

        try {
            $result = $this->researchNews->researchForNews($news);

            if ($result === null) {
                Log::warning('news_ai: investigación vacía antes de ficha', ['news_id' => $news->id]);

                return;
            }

            $detail->update([
                'research_context' => $result['context'],
                'research_raw' => $result['raw'],
                'fact_sheet' => null,
                'researched_at' => now(),
            ]);

            $news->refresh();
            $news->load('detail');

            Log::info('news_ai: investigación completada on-demand', ['news_id' => $news->id]);
        } catch (Throwable $e) {
            Log::warning('news_ai: investigación on-demand falló', [
                'news_id' => $news->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
