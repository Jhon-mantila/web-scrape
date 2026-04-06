<?php

namespace App\SendWordpress\Actions;

use App\Models\NewsAiArticle;
use App\SendWordpress\WordPressClient;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendPostToWordpressAction
{
    public function __construct(
        private readonly WordPressClient $client
    ) {}

    public function execute(int $limit, string $mode): array
    {
        $processed = 0;
        $success = 0;
        $failed = 0;

        $items = NewsAiArticle::query()
            ->where('sent_wordpress', false)
            ->whereNotNull('body_html')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($items as $article) {
            $processed++;

            try {
                $status = match ($mode) {
                    'draft' => 'draft',
                    'publish' => 'publish',
                    'schedule' => 'future',
                    default => 'draft'
                };

                $payload = [
                    'title'   => $article->generated_title ?? $article->source_title,
                    'content' => $article->body_html,
                    'excerpt' => $article->excerpt,
                    'status'  => $status,
                ];

                // ⏰ si es programado
                if ($mode === 'schedule') {
                    $payload['date'] = now()->addHours(2)->toIso8601String();
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
                \Log::error('wordpress: fallo al enviar', [
                    'news_ai_id' => $article->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return compact('processed', 'success', 'failed');
    }
}