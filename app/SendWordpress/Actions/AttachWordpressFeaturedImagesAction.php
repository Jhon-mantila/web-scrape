<?php

namespace App\SendWordpress\Actions;

use App\Models\NewsAiArticle;
use App\SendWordpress\Support\WordpressFeaturedMediaUploader;
use App\SendWordpress\WordPressAccountPool;
use App\SendWordpress\WordPressClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class AttachWordpressFeaturedImagesAction
{
    public function __construct(
        private readonly WordPressClient $client,
        private readonly WordPressAccountPool $accountPool,
        private readonly WordpressFeaturedMediaUploader $uploader,
    ) {}

    /**
     * @return array{
     *     scanned: int,
     *     attached: int,
     *     skipped_has_featured: int,
     *     skipped_no_local_image: int,
     *     failed: int
     * }
     */
    public function execute(int $limit, bool $onlyMissing = true): array
    {
        $scanned = 0;
        $attached = 0;
        $skippedHasFeatured = 0;
        $skippedNoLocalImage = 0;
        $failed = 0;

        $candidates = NewsAiArticle::query()
            ->with(['news.detail'])
            ->where('sent_wordpress', true)
            ->whereNotNull('wordpress_post_id')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $article) {
            if ($limit > 0 && $attached >= $limit) {
                break;
            }

            $postId = (int) $article->wordpress_post_id;

            if ($postId <= 0) {
                continue;
            }

            $scanned++;
            $account = $this->accountPool->forUsername($article->wordpress_author);

            try {
                if ($onlyMissing) {
                    $post = $this->client->getPost($postId, $account, [
                        'id',
                        'featured_media',
                    ]);
                    $featuredMedia = (int) ($post['featured_media'] ?? 0);

                    if ($featuredMedia > 0) {
                        $skippedHasFeatured++;

                        continue;
                    }
                }

                $mediaId = $this->uploader->uploadForArticle($article, $account);

                if ($mediaId === null) {
                    $skippedNoLocalImage++;

                    continue;
                }

                $this->client->updatePost($postId, [
                    'featured_media' => $mediaId,
                ], $account);

                Log::info('wordpress: imagen destacada adjuntada al post', [
                    'news_ai_id' => $article->id,
                    'news_id' => $article->news_id,
                    'wordpress_post_id' => $postId,
                    'featured_media' => $mediaId,
                ]);

                $attached++;
            } catch (Throwable $e) {
                $failed++;
                Log::error('wordpress: fallo al adjuntar imagen destacada', [
                    'news_ai_id' => $article->id,
                    'wordpress_post_id' => $postId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'scanned' => $scanned,
            'attached' => $attached,
            'skipped_has_featured' => $skippedHasFeatured,
            'skipped_no_local_image' => $skippedNoLocalImage,
            'failed' => $failed,
        ];
    }
}
