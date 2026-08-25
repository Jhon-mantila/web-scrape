<?php

namespace App\SendWordpress\Actions;

use App\Models\NewsAiArticle;
use App\ProcessScraping\Actions\DownloadFeaturedImagesAction;
use App\ProcessScraping\Support\HtmlArticleSanitizer;
use App\ProcessScraping\Support\YoutubeExtractor;
use App\SendWordpress\WordPressAccount;
use App\SendWordpress\WordPressAccountPool;
use App\SendWordpress\WordPressClient;
use App\SendWordpress\WordPressSchedulePlanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SendPostToWordpressAction
{
    public function __construct(
        private readonly WordPressClient $client,
        private readonly DownloadFeaturedImagesAction $downloadImages,
        private readonly WordPressSchedulePlanner $schedulePlanner,
        private readonly WordPressAccountPool $accountPool,
    ) {}

    /**
     * @return array{
     *     processed: int,
     *     success: int,
     *     failed: int,
     *     scheduled: list<array{news_id: int, scheduled_at: string, author: string}>,
     *     by_author: array<string, int>
     * }
     */
    public function execute(int $limit, string $mode, array $newsIds = []): array
    {
        $processed = 0;
        $success = 0;
        $failed = 0;
        $scheduled = [];
        $byAuthor = [];

        $query = NewsAiArticle::with(['news.detail'])
            ->where('sent_wordpress', false)
            ->whereNotNull('body_html')
            ->orderBy('id');

        if ($newsIds !== []) {
            $query->whereIn('news_id', $newsIds);
        }

        $items = $query->limit($limit)->get();
        $accountIndex = 0;

        foreach ($items as $article) {
            $processed++;
            $account = $this->accountPool->forIndex($accountIndex);
            $accountIndex++;

            try {
                $status = match ($mode) {
                    'draft' => 'draft',
                    'publish' => 'publish',
                    'schedule' => 'future',
                    default => 'draft'
                };

                $categoryName = $article->news->category ?? 'General';
                $categoryId = $this->client->getOrCreateCategory($categoryName, $account);

                $allowedEmbeds = YoutubeExtractor::collect(
                    $article->news->detail?->raw_html,
                    $article->news->detail?->research_context,
                    $article->news->detail?->research_raw,
                );

                $postTitle = trim((string) ($article->generated_title ?? ''));

                $payload = [
                    'title' => $postTitle,
                    'content' => HtmlArticleSanitizer::sanitize($article->body_html, $allowedEmbeds),
                    'excerpt' => $article->excerpt,
                    'status' => $status,
                    'categories' => [$categoryId],
                ];

                if ($mode === 'schedule') {
                    $scheduledAt = $this->schedulePlanner->nextScheduledAt();
                    $payload['date'] = $scheduledAt->toIso8601String();
                    $scheduled[] = [
                        'news_id' => $article->news_id,
                        'scheduled_at' => $scheduledAt->toIso8601String(),
                        'author' => $account->user,
                    ];
                }

                $featuredMediaId = $this->resolveFeaturedMediaId($article, $account, $postTitle);
                if ($featuredMediaId !== null) {
                    $payload['featured_media'] = $featuredMediaId;
                }

                $this->client->createPost($payload, $account);

                DB::transaction(function () use ($article) {
                    $article->update([
                        'sent_wordpress' => true,
                        'sent_wordpress_at' => now(),
                    ]);
                });

                $byAuthor[$account->user] = ($byAuthor[$account->user] ?? 0) + 1;

                Log::info('wordpress: artículo enviado', [
                    'news_ai_id' => $article->id,
                    'news_id' => $article->news_id,
                    'author' => $account->user,
                    'mode' => $mode,
                    'scheduled_at' => $payload['date'] ?? null,
                ]);

                $success++;

            } catch (Throwable $e) {
                $failed++;
                Log::error('wordpress: fallo al enviar', [
                    'news_ai_id' => $article->id,
                    'author' => $account->user,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'processed' => $processed,
            'success' => $success,
            'failed' => $failed,
            'scheduled' => $scheduled,
            'by_author' => $byAuthor,
        ];
    }

    private function resolveFeaturedMediaId(NewsAiArticle $article, WordPressAccount $account, string $title): ?int
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
            $media = $this->client->uploadMedia($fullPath, basename($imagePath), $account, $title);
            $mediaId = $media['id'] ?? null;

            Log::info('wordpress: imagen destacada subida', [
                'news_ai_id' => $article->id,
                'media_id' => $mediaId,
                'path' => $imagePath,
                'author' => $account->user,
                'media_title' => $title,
                'alt_text' => $media['alt_text'] ?? $title,
            ]);

            return $mediaId;
        } catch (Throwable $e) {
            Log::warning('wordpress: no se pudo subir imagen destacada', [
                'news_ai_id' => $article->id,
                'path' => $imagePath,
                'author' => $account->user,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
