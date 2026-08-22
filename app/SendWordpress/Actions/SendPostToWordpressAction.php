<?php

namespace App\SendWordpress\Actions;

use App\Models\NewsAiArticle;
use App\ProcessScraping\Actions\DownloadFeaturedImagesAction;
use App\ProcessScraping\Support\HtmlArticleSanitizer;
use App\ProcessScraping\Support\YoutubeExtractor;
use App\SendWordpress\WordPressClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SendPostToWordpressAction
{
    public function __construct(
        private readonly WordPressClient $client,
        private readonly DownloadFeaturedImagesAction $downloadImages,
    ) {}

    public function execute(int $limit, string $mode, array $newsIds = []): array
    {
        $processed = 0;
        $success = 0;
        $failed = 0;

        $query = NewsAiArticle::with(['news.detail'])
            ->where('sent_wordpress', false)
            ->whereNotNull('body_html')
            ->orderBy('id');

        if ($newsIds !== []) {
            $query->whereIn('news_id', $newsIds);
        }

        $items = $query->limit($limit)->get();

        foreach ($items as $article) {
            $processed++;

            try {
                $status = match ($mode) {
                    'draft' => 'draft',
                    'publish' => 'publish',
                    'schedule' => 'future',
                    default => 'draft'
                };

                $categoryName = $article->news->category ?? 'General';
                $categoryId = $this->client->getOrCreateCategory($categoryName);

                $allowedEmbeds = YoutubeExtractor::extract($article->news->detail?->raw_html);

                $payload = [
                    'title' => $article->generated_title ?? $article->source_title,
                    'content' => HtmlArticleSanitizer::sanitize($article->body_html, $allowedEmbeds),
                    'excerpt' => $article->excerpt,
                    'status' => $status,
                    'categories' => [$categoryId],
                ];

                if ($mode === 'schedule') {
                    $payload['date'] = now()->addHours(2)->toIso8601String();
                }

                $featuredMediaId = $this->resolveFeaturedMediaId($article);
                if ($featuredMediaId !== null) {
                    $payload['featured_media'] = $featuredMediaId;
                }

                $this->client->createPost($payload);

                DB::transaction(function () use ($article) {
                    $article->update([
                        'sent_wordpress' => true,
                        'sent_wordpress_at' => now(),
                    ]);
                });

                $success++;

            } catch (Throwable $e) {
                $failed++;
                Log::error('wordpress: fallo al enviar', [
                    'news_ai_id' => $article->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return compact('processed', 'success', 'failed');
    }

    private function resolveFeaturedMediaId(NewsAiArticle $article): ?int
    {
        $article->loadMissing('news.detail');
        $imagePath = $article->news->detail?->featured_image_path;

        if (($imagePath === null || $imagePath === '') && $article->news !== null) {
            $this->downloadImages->downloadForNews($article->news);
            $article->load('news.detail');
            $imagePath = $article->news->detail?->featured_image_path;
        }

        if ($imagePath === null || $imagePath === '') {
            Log::info('wordpress: sin imagen destacada para el artículo', [
                'news_ai_id' => $article->id,
                'news_id' => $article->news_id,
            ]);

            return null;
        }

        if (! Storage::disk('public')->exists($imagePath)) {
            return null;
        }

        $fullPath = Storage::disk('public')->path($imagePath);

        try {
            $media = $this->client->uploadMedia($fullPath, basename($imagePath));
            $mediaId = $media['id'] ?? null;

            Log::info('wordpress: imagen destacada subida', [
                'news_ai_id' => $article->id,
                'media_id' => $mediaId,
                'path' => $imagePath,
            ]);

            return $mediaId;
        } catch (Throwable $e) {
            Log::warning('wordpress: no se pudo subir imagen destacada', [
                'news_ai_id' => $article->id,
                'path' => $imagePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
