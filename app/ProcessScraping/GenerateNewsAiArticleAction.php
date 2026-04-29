<?php

namespace App\ProcessScraping;

use App\Models\News;
use App\Models\NewsAiArticle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\ProcessScraping\YoutubeExtractor;

class GenerateNewsAiArticleAction
{
    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly AiArticleResponseParser $parser,
    ) {}

    /**
     * @return array{processed: int, success: int, failed: int, errors: list<array{news_id: int, message: string}>}
     */
    public function execute(int $limit, bool $force, bool $includeRawHtml): array
    {
        $processed = 0;
        $success = 0;
        $failed = 0;
        $errors = [];

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

        /** @var iterable<News> $items */
        $items = $query->limit($limit)->get();

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
                
                $body = null;
                $parts = null;
                
                for ($i = 0; $i < 3; $i++) {

                    $youtubeEmbeds = YoutubeExtractor::extract($detail->raw_html ?? null);

                    $prompt = ArticleGenerationPrompt::user(
                        $news->title,
                        $detail->content_text,
                        $rawHtml,
                        $news->source,
                        $youtubeEmbeds // 👈 nuevo parámetro
                    );
                
                    // 🔥 segundo intento más agresivo
                    if ($i > 0) {
                        
                        $prompt .= "\n\nTu respuesta anterior no fue JSON válido. Responde SOLO con JSON puro, sin ``` ni texto adicional.";
                    }
                
                    $body = $this->ollama->generate(
                        ArticleGenerationPrompt::system(),
                        $prompt,
                    );
                
                    Log::info('news_ai: intento '.$i, [
                        'news_id' => $news->id,
                        'response' => $body,
                    ]);
                
                    $parts = $this->parser->parse($body);
                
                    if (!empty($parts['body_html'])) {
                        break;
                    }
                }
                // 🔥 VALIDACIONES FINALES

                // título fallback
                if (empty($parts['generated_title'])) {
                    $parts['generated_title'] = $news->title;
                }

                // excerpt limpio
                if (!empty($parts['excerpt'])) {
                    $parts['excerpt'] = trim($parts['excerpt']);

                    if (mb_strlen($parts['excerpt']) < 30) {
                        $parts['excerpt'] = null;
                    }
                }

                // fallback desde HTML
                if (empty($parts['excerpt']) && !empty($parts['body_html'])) {
                    $text = trim(strip_tags($parts['body_html']));
                
                    // cortar sin romper palabras
                    $excerpt = mb_substr($text, 0, 200);
                    $excerpt = preg_replace('/\s+\S*$/u', '', $excerpt);
                
                    $parts['excerpt'] = $excerpt;
                }

                // ❌ si no hay HTML, fallo inmediato
                if (empty($parts['body_html'])) {
                    throw new \RuntimeException('Respuesta sin HTML válido');
                }

                DB::transaction(function () use ($news, $parts): void {
                    NewsAiArticle::updateOrCreate(
                        ['news_id' => $news->id],
                        [
                            'source_title' => $news->title,
                            'generated_title' => $parts['generated_title'],
                            'excerpt' => $parts['excerpt'],
                            'body_html' => $parts['body_html'],
                            'model' => config('services.ollama.model'),
                        ],
                    );
                    $news->update(['status_ia' => 'processed']);
                });

                $success++;
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

        return compact('processed', 'success', 'failed', 'errors');
    }
}
