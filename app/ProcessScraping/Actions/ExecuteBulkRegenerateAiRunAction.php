<?php

namespace App\ProcessScraping\Actions;

use App\Models\News;
use App\ProcessScraping\Ai\Support\AiSettings;
use App\ProcessScraping\Support\AiGenerationProgress;
use App\ProcessScraping\Support\BackgroundRunQueue;
use App\ProcessScraping\Support\ScraperArticleAiContext;
use App\SocialPublishing\Support\VideoCaptionAiClientFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteBulkRegenerateAiRunAction
{
    public function __construct(
        private readonly GenerateNewsAiArticleAction $generateAi,
        private readonly VideoCaptionAiClientFactory $clientFactory,
    ) {}

    public function execute(int $userId, string $runId): void
    {
        $run = BackgroundRunQueue::find($userId, $runId);

        if ($run === null) {
            return;
        }

        $options = $run['options'] ?? ($run['job']['options'] ?? []);
        /** @var list<int> $newsIds */
        $newsIds = array_values(array_unique(array_map('intval', $options['news_ids'] ?? [])));

        if ($newsIds === []) {
            BackgroundRunQueue::failRun($userId, $runId, 'No hay noticias seleccionadas.');

            return;
        }

        ScraperArticleAiContext::applyFromPipelineOptions($options);

        $processed = 0;
        $success = 0;
        $failed = 0;
        $tokensTotal = 0;
        /** @var list<array{news_id: int, message: string}> $errors */

        $errors = [];

        try {
            foreach ($newsIds as $newsId) {
                $stepKey = 'news_'.$newsId;
                BackgroundRunQueue::beginStep($userId, $runId, $stepKey, "Regenerando IA · noticia #{$newsId}…");

                $news = News::query()->with(['detail', 'aiArticle'])->find($newsId);

                if ($news === null) {
                    $failed++;
                    $errors[] = ['news_id' => $newsId, 'message' => 'Noticia no encontrada.'];
                    BackgroundRunQueue::finishStep($userId, $runId, $stepKey, 'No encontrada');

                    continue;
                }

                if ($news->aiArticle?->sent_wordpress) {
                    $failed++;
                    $errors[] = ['news_id' => $newsId, 'message' => 'Ya enviada a WordPress.'];
                    BackgroundRunQueue::finishStep($userId, $runId, $stepKey, 'Omitida (WP)');

                    continue;
                }

                if ($news->detail?->status !== 'processed') {
                    $failed++;
                    $errors[] = ['news_id' => $newsId, 'message' => 'Detalle no procesado.'];
                    BackgroundRunQueue::finishStep($userId, $runId, $stepKey, 'Detalle incompleto');

                    continue;
                }

                $processed++;

                try {
                    $summary = $this->generateAi->execute(
                        1,
                        true,
                        (bool) ($options['include_raw_html'] ?? true),
                        [$newsId],
                        false,
                        $userId,
                    );

                    $snap = AiGenerationProgress::snapshot($newsId);
                    $runTokens = (int) ($summary['tokens_total'] ?? $snap['tokens_total'] ?? 0);
                    $tokensTotal += $runTokens;

                    if ($summary['success'] >= 1) {
                        $success++;
                        $elapsed = (float) ($snap['elapsed_seconds'] ?? 0);
                        $detail = "OK · {$runTokens} tokens";

                        if ($elapsed > 0) {
                            $detail .= ' · '.round($elapsed, 1).' s';
                        }

                        BackgroundRunQueue::finishStep($userId, $runId, $stepKey, $detail);
                    } else {
                        $failed++;
                        $message = $summary['errors'][0]['message'] ?? 'No se pudo regenerar.';
                        $errors[] = ['news_id' => $newsId, 'message' => $message];
                        BackgroundRunQueue::finishStep($userId, $runId, $stepKey, mb_substr($message, 0, 120));
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $errors[] = ['news_id' => $newsId, 'message' => $e->getMessage()];
                    BackgroundRunQueue::finishStep($userId, $runId, $stepKey, 'Error');
                    Log::warning('bulk_regenerate_ai: fallo', ['news_id' => $newsId, 'error' => $e->getMessage()]);
                }
            }

            if (AiSettings::shouldUnloadAfterGenerate() && $success > 0) {
                $provider = ScraperArticleAiContext::get();
                $llm = $this->clientFactory->client($provider);

                if ($llm->supportsModelUnload()) {
                    $llm->unloadModels();
                }
            }
        } finally {
            ScraperArticleAiContext::reset();
        }

        $summary = [
            'processed' => $processed,
            'success' => $success,
            'failed' => $failed,
            'tokens_total' => $tokensTotal,
            'errors' => $errors,
        ];

        $message = "Regeneración IA: {$success}/{$processed} OK";

        if ($tokensTotal > 0) {
            $message .= " · {$tokensTotal} tokens";
        }

        if ($failed > 0) {
            $message .= " · {$failed} fallidas";
        }

        BackgroundRunQueue::completeRun($userId, $runId, $summary, $message, $failed > 0 && $success === 0);
    }
}
