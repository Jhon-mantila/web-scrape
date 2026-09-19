<?php

namespace App\SendWordpress\Support;

use App\Models\NewsAiArticle;
use App\ProcessScraping\Actions\DownloadFeaturedImagesAction;
use App\SendWordpress\WordPressAccount;
use App\SendWordpress\WordPressClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class WordpressFeaturedMediaUploader
{
    public function __construct(
        private readonly WordPressClient $client,
        private readonly DownloadFeaturedImagesAction $downloadImages,
    ) {}

    public function uploadForArticle(NewsAiArticle $article, WordPressAccount $account): ?int
    {
        $article->loadMissing('news.detail');
        $imagePath = $article->news->detail?->featured_image_path;
        $resolvedPath = $this->downloadImages->resolveExistingStoragePath($imagePath);

        if ($resolvedPath === null && $article->news !== null) {
            $this->downloadImages->downloadForNews($article->news);
            $article->load('news.detail');
            $imagePath = $article->news->detail?->featured_image_path;
            $resolvedPath = $this->downloadImages->resolveExistingStoragePath($imagePath);
        }

        if ($resolvedPath === null) {
            Log::info('wordpress: sin imagen destacada local para el artículo', [
                'news_ai_id' => $article->id,
                'news_id' => $article->news_id,
                'stored_path' => $imagePath,
            ]);

            return null;
        }

        if (! Storage::disk('public')->exists($resolvedPath)) {
            return null;
        }

        $postTitle = trim((string) ($article->generated_title ?? $article->news?->title ?? ''));

        try {
            $fullPath = Storage::disk('public')->path($resolvedPath);
            $media = $this->client->uploadMedia($fullPath, basename($resolvedPath), $account, $postTitle);
            $mediaId = isset($media['id']) ? (int) $media['id'] : null;

            Log::info('wordpress: imagen destacada subida', [
                'news_ai_id' => $article->id,
                'media_id' => $mediaId,
                'path' => $resolvedPath,
                'author' => $account->user,
            ]);

            return $mediaId;
        } catch (Throwable $e) {
            Log::warning('wordpress: no se pudo subir imagen destacada', [
                'news_ai_id' => $article->id,
                'path' => $resolvedPath,
                'author' => $account->user,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
