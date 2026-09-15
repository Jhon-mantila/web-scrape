<?php

namespace App\SendWordpress\Actions;

use App\Models\NewsAiArticle;
use App\SendWordpress\Support\WordpressPostMetaParser;
use App\SendWordpress\WordPressClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncWordpressScraperStatusAction
{
    public function __construct(
        private readonly WordPressClient $client,
        private readonly WordpressPostMetaParser $parser,
    ) {}

    /**
     * @return array{total: int, updated: int, errors: int, skipped: int}
     */
    public function execute(): array
    {
        $articles = NewsAiArticle::query()
            ->whereNotNull('wordpress_post_id')
            ->get();

        $updated = 0;
        $errors = 0;
        $skipped = 0;

        foreach ($articles as $article) {
            $postId = (int) $article->wordpress_post_id;

            if ($postId <= 0) {
                $skipped++;

                continue;
            }

            try {
                $post = $this->client->getPost($postId);
                $meta = $this->parser->fromApiPost($post);

                $article->update([
                    'wordpress_status' => $meta['status'],
                    'wordpress_scheduled_at' => $meta['scheduled_at'],
                    'wordpress_url' => $meta['url'],
                ]);

                $updated++;
            } catch (Throwable $e) {
                $errors++;
                Log::warning('wordpress: no se pudo sincronizar estado del post', [
                    'news_ai_id' => $article->id,
                    'wordpress_post_id' => $postId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'total' => $articles->count(),
            'updated' => $updated,
            'errors' => $errors,
            'skipped' => $skipped,
        ];
    }
}
