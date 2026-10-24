<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\News;
use App\Models\NewsDetail;
use App\Neuron\Agents\EditorialAgent;
use App\ProcessScraping\Ai\AiArticleResponseParser;
use App\ProcessScraping\Prompts\AnimeNewsWritingGuidelines;
use App\ProcessScraping\Support\AiGenerationProgress;
use App\ProcessScraping\Support\ArticleQualityReviewer;
use App\ProcessScraping\Support\ArticleWordCounter;
use Illuminate\Support\Facades\Log;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

/**
 * Opción A: ciclo editorial con múltiples llamadas chat() al {@see EditorialAgent}.
 * Cada paso emite logs explícitos y actualiza news_details.ai_status.
 */
class EditorialService
{
    public function __construct(
        private readonly AiArticleResponseParser $parser,
        private readonly ArticleQualityReviewer $qualityReviewer,
        private readonly ?EditorialAgent $agent = null,
    ) {}

    /**
     * @return array{parts: array{generated_title: ?string, excerpt: ?string, body_html: ?string}, raw: string, tags: list<string>}
     */
    public function generateForNews(News $news, string $articleType, ?string $sourceHint = null): array
    {
        $news->loadMissing(['detail', 'aiArticle']);
        $detail = $news->detail;

        if ($detail === null) {
            throw new \RuntimeException('Detalle de noticia ausente');
        }

        $startedAt = microtime(true);
        $totalTokens = 0;
        $rawParts = [];

        try {
            $this->setAiStatus($detail, 'pending');

            $agent = $this->resolveAgent($news);
            $detailId = $detail->id;
            $title = $news->title;
            $scraped = mb_substr((string) $detail->content_text, 0, 12000);
            $source = $sourceHint ?? $news->source ?? 'unknown';
            $minWords = (int) config('services.scraper.anime_news_min_words', 350);
            $seoAnchor = $this->buildSeoAnchor($news);
            $writingRules = AnimeNewsWritingGuidelines::researchInstructions();

            $this->logStep($news->id, 1, 4, 'Planificando outline');
            $this->setAiStatus($detail, 'planning');

            $planPrompt = <<<PROMPT
Paso 1 — Planificación.
Noticia: {$title}
Fuente: {$source}
Tipo de artículo: {$articleType}
news_detail_id para investigación: {$detailId}

Texto scrapeado (extracto):
{$scraped}

Usa la herramienta research_context con news_detail_id={$detailId} antes de planificar.
Outline y título propuesto en ESPAÑOL (SEO ≤70 caracteres).
{$seoAnchor}
{$writingRules}
Responde SOLO con un outline en Markdown: título propuesto, secciones (##) y puntos clave por sección.
PROMPT;

            $planState = $agent->chat(new UserMessage($planPrompt));
            $outline = (string) ($planState->getMessage()?->getContent() ?? '');
            $planTokens = $this->usageTotal($planState);
            $totalTokens += $planTokens;
            $rawParts[] = $outline;
            $this->logStep($news->id, 1, 4, 'Planificación lista', $planTokens);

            $sectionCount = max(1, preg_match_all('/^##\s+/m', $outline) ?: 1);

            $this->logStep($news->id, 2, 4, "Redactando borrador ({$sectionCount} secciones)");
            $this->setAiStatus($detail, 'drafting');

            $draftPrompt = <<<PROMPT
Paso 2 — Redacción.
Outline aprobado:
{$outline}

Usa research_context (news_detail_id={$detailId}) para datos verificados.
Idioma: español neutro obligatorio en title, excerpt y html. NUNCA dejes el title en inglés aunque el titular ANN esté en inglés.
Título SEO ≤70 caracteres en español. Meta/excerpt ≤155 caracteres.
Extensión mínima del HTML: {$minWords} palabras visibles; ideal 500–800 con <h2> por sección (sin <h1>).
{$seoAnchor}
Responde SOLO con JSON válido: {"title":"...","excerpt":"...","html":"<p>...</p>"}
Sin markdown ni fences.
PROMPT;

            $draftState = $agent->chat(new UserMessage($draftPrompt));
            $draftRaw = (string) ($draftState->getMessage()?->getContent() ?? '');
            $draftTokens = $this->usageTotal($draftState);
            $totalTokens += $draftTokens;
            $rawParts[] = $draftRaw;
            $draftParts = $this->parser->parse($draftRaw);
            $this->logStep($news->id, 2, 4, 'Borrador listo', $draftTokens);

            $this->logStep($news->id, 3, 4, 'Autocriticando borrador');
            $this->setAiStatus($detail, 'critiquing');

            $critiquePrompt = <<<PROMPT
Paso 3 — Autocrítica.
Borrador JSON:
{$draftRaw}

Compara con research_context (news_detail_id={$detailId}).
Responde SOLO JSON: {"needs_refinement":true|false,"feedback":"resumen corto","issues":["..."]}
PROMPT;

            $critiqueState = $agent->chat(new UserMessage($critiquePrompt));
            $critiqueRaw = (string) ($critiqueState->getMessage()?->getContent() ?? '');
            $critiqueTokens = $this->usageTotal($critiqueState);
            $totalTokens += $critiqueTokens;
            $rawParts[] = $critiqueRaw;
            $critique = $this->decodeJsonObject($critiqueRaw);
            $needsRefinement = (bool) ($critique['needs_refinement'] ?? false);
            $this->logStep($news->id, 3, 4, 'Autocrítica lista', $critiqueTokens);

            $finalRaw = $draftRaw;
            $finalParts = $draftParts;

            if ($needsRefinement) {
                $feedback = (string) ($critique['feedback'] ?? '');
                $this->logStep($news->id, 4, 4, 'Refinando según feedback');
                $this->setAiStatus($detail, 'refining');

                $refinePrompt = <<<PROMPT
Paso 4 — Refinamiento.
Feedback del editor:
{$feedback}

Borrador anterior:
{$draftRaw}

Investigación: usa research_context (news_detail_id={$detailId}).
Mantén español, SEO y mínimo {$minWords} palabras en html.
{$seoAnchor}
Responde SOLO JSON: {"title":"...","excerpt":"...","html":"<p>...</p>"}
PROMPT;

                $refineState = $agent->chat(new UserMessage($refinePrompt));
                $finalRaw = (string) ($refineState->getMessage()?->getContent() ?? '');
                $refineTokens = $this->usageTotal($refineState);
                $totalTokens += $refineTokens;
                $rawParts[] = $finalRaw;
                $finalParts = $this->parser->parse($finalRaw);
                $this->logStep($news->id, 4, 4, 'Refinamiento listo', $refineTokens);
            } else {
                $this->logStep($news->id, 4, 4, 'Refinamiento omitido (autocrítica OK)');
            }

            if (empty($finalParts['body_html'])) {
                throw new \RuntimeException('El agente editorial no devolvió HTML válido');
            }

            $finalParts = $this->expandIfTooShort(
                $agent,
                $news->id,
                $detailId,
                $finalParts,
                $finalRaw,
                $minWords,
                $seoAnchor,
                $totalTokens,
                $rawParts,
            );

            $finalParts = $this->fixSpanishTitleIfNeeded(
                $agent,
                $news,
                $detailId,
                $finalParts,
                $finalRaw,
                $totalTokens,
                $rawParts,
            );

            $elapsed = round(microtime(true) - $startedAt, 2);
            AiGenerationProgress::complete($news->id, $totalTokens, $elapsed);
            $tokenNote = $totalTokens > 0 ? (string) $totalTokens : 'n/d';
            Log::channel('editorial_agent')->info(
                "[EditorialAgent] Completado en {$elapsed}s, {$tokenNote} tokens usados",
                ['news_id' => $news->id, 'news_detail_id' => $detailId]
            );

            $this->setAiStatus($detail, 'completed');

            return [
                'parts' => $finalParts,
                'raw' => implode("\n\n---\n\n", $rawParts),
                'tags' => [],
            ];
        } catch (Throwable $e) {
            if ($detail !== null) {
                $this->setAiStatus($detail, 'failed');
            }

            throw $e;
        }
    }

    /**
     * @param  list<string>  $rawParts
     * @return array{parts: array{generated_title: ?string, excerpt: ?string, body_html: ?string}, raw: string}
     */
    private function expandIfTooShort(
        EditorialAgent $agent,
        int $newsId,
        int $detailId,
        array $finalParts,
        string $finalRaw,
        int $minWords,
        string $seoAnchor,
        int &$totalTokens,
        array &$rawParts,
    ): array {
        $html = (string) ($finalParts['body_html'] ?? '');
        $wordCount = ArticleWordCounter::fromHtml($html);

        for ($attempt = 0; $attempt < 2 && $wordCount < $minWords; $attempt++) {
            $this->logStep($newsId, 4, 4, "Expansión ({$wordCount}/{$minWords} pal.) intento ".($attempt + 1));

            $expandPrompt = <<<PROMPT
El borrador es demasiado corto ({$wordCount} palabras; mínimo {$minWords}).
Expande el HTML desarrollando cada <h2> con varios párrafos sustantivos, sin relleno ni frases plantilla.
Usa research_context (news_detail_id={$detailId}) solo para hechos verificables.
Mantén español neutro, título SEO ≤70 caracteres y la misma línea editorial.
{$seoAnchor}
Borrador actual:
{$finalRaw}

Responde SOLO JSON: {"title":"...","excerpt":"...","html":"..."}
PROMPT;

            $expandState = $agent->chat(new UserMessage($expandPrompt));
            $finalRaw = (string) ($expandState->getMessage()?->getContent() ?? '');
            $expandTokens = $this->usageTotal($expandState);
            $totalTokens += $expandTokens;
            $rawParts[] = $finalRaw;
            $parsed = $this->parser->parse($finalRaw);

            if (! empty($parsed['body_html'])) {
                $finalParts = $parsed;
                $html = (string) $parsed['body_html'];
                $wordCount = ArticleWordCounter::fromHtml($html);
                $this->logStep($newsId, 4, 4, "Expansión · {$wordCount} palabras", $expandTokens);
            }
        }

        return $finalParts;
    }

    private function buildSeoAnchor(News $news): string
    {
        $existing = $news->aiArticle;

        if ($existing === null || blank($existing->generated_title)) {
            return '';
        }

        $title = trim((string) $existing->generated_title);

        if (! $this->qualityReviewer->isAcceptableSpanishSeoReference($title)) {
            return '';
        }

        $block = "Referencia SEO previa (conservar tono e idioma español si sigue siendo válida):\n"
            .'Título: '.$title;

        if (filled($existing->excerpt)) {
            $block .= "\nExtracto: ".trim((string) $existing->excerpt);
        }

        return $block."\n";
    }

    /**
     * @param  list<string>  $rawParts
     * @return array{generated_title: ?string, excerpt: ?string, body_html: ?string}
     */
    private function fixSpanishTitleIfNeeded(
        EditorialAgent $agent,
        News $news,
        int $detailId,
        array $finalParts,
        string $finalRaw,
        int &$totalTokens,
        array &$rawParts,
    ): array {
        $titleError = $this->qualityReviewer->validateGeneratedTitle($finalParts['generated_title'] ?? null);

        if ($titleError === null) {
            return $finalParts;
        }

        $this->logStep($news->id, 4, 4, 'Corrigiendo título SEO (español)');

        $badTitle = (string) ($finalParts['generated_title'] ?? '');
        $annTitle = trim((string) $news->title);

        $fixPrompt = <<<PROMPT
El campo "title" del JSON no es válido: {$titleError}
Título rechazado: {$badTitle}

Reescribe el artículo con un title en ESPAÑOL neutro (SEO ≤70 caracteres), sin traducir literalmente el titular ANN.
Titular ANN (solo referencia): {$annTitle}

Usa research_context (news_detail_id={$detailId}) si hace falta.
Mantén el html lo más posible; ajusta excerpt si conviene.

Borrador anterior:
{$finalRaw}

Responde SOLO JSON: {"title":"...","excerpt":"...","html":"..."}
PROMPT;

        $fixState = $agent->chat(new UserMessage($fixPrompt));
        $fixRaw = (string) ($fixState->getMessage()?->getContent() ?? '');
        $fixTokens = $this->usageTotal($fixState);
        $totalTokens += $fixTokens;
        $rawParts[] = $fixRaw;
        $parsed = $this->parser->parse($fixRaw);

        if (! empty($parsed['body_html'])) {
            $this->logStep($news->id, 4, 4, 'Título SEO corregido', $fixTokens);

            return $parsed;
        }

        return $finalParts;
    }

    private function resolveAgent(News $news): EditorialAgent
    {
        $agent = $this->agent ?? new EditorialAgent();

        // Neuron 4.x: chat() exige thread ID (workflow ID) antes de ejecutar.
        $threadId = sprintf(
            'editorial-news-%d-detail-%d-%s',
            $news->id,
            (int) ($news->detail?->id ?? 0),
            bin2hex(random_bytes(8)),
        );
        $agent->setThreadId($threadId);

        return $agent;
    }

    private function setAiStatus(NewsDetail $detail, string $status): void
    {
        $detail->forceFill([
            'ai_status' => $status,
            'ai_status_updated_at' => now(),
        ])->save();
    }

    private function logStep(int $newsId, int $step, int $total, string $message, int $tokensDelta = 0): void
    {
        Log::channel('editorial_agent')->info("[EditorialAgent] Paso {$step}/{$total}: {$message}");
        AiGenerationProgress::pulse($newsId, $step, $total, $message, $tokensDelta);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $raw): array
    {
        $text = trim($raw);
        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $data = json_decode($m[0], true);
            if (is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    private function usageTotal(\NeuronAI\Agent\AgentState $state): int
    {
        $usage = $state->getMessage()?->getUsage();

        return $usage !== null ? $usage->getTotal() : 0;
    }
}
