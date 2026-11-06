<?php

namespace Tests\Feature;

use App\Models\News;
use App\Models\NewsAiArticle;
use App\Models\NewsDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscardNewsAiPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_discards_ai_article_and_resets_status_when_not_sent_to_wordpress(): void
    {
        $user = User::factory()->create();

        $news = News::query()->create([
            'title' => 'Test',
            'url' => 'https://example.com/1',
            'source' => 'anime_news',
            'status_ia' => 'processed',
        ]);

        NewsDetail::query()->create([
            'news_id' => $news->id,
            'status' => 'processed',
            'content_text' => 'Texto',
            'raw_html' => '<p>x</p>',
            'ai_status' => 'completed',
            'ai_status_updated_at' => now(),
        ]);

        NewsAiArticle::query()->create([
            'news_id' => $news->id,
            'generated_title' => 'Título IA',
            'body_html' => '<p>Cuerpo</p>',
            'sent_wordpress' => false,
        ]);

        $this->actingAs($user)
            ->delete(route('scraper.discard-ai', $news))
            ->assertRedirect();

        $news->refresh();
        $this->assertNull($news->status_ia);
        $this->assertNull($news->aiArticle);
        $this->assertNull($news->detail?->ai_status);
    }

    public function test_cannot_discard_when_sent_to_wordpress(): void
    {
        $user = User::factory()->create();

        $news = News::query()->create([
            'title' => 'Test',
            'url' => 'https://example.com/2',
            'source' => 'anime_news',
            'status_ia' => 'processed',
        ]);

        NewsAiArticle::query()->create([
            'news_id' => $news->id,
            'generated_title' => 'Título IA',
            'body_html' => '<p>Cuerpo</p>',
            'sent_wordpress' => true,
        ]);

        $this->actingAs($user)
            ->from(route('scraper.index'))
            ->delete(route('scraper.discard-ai', $news))
            ->assertRedirect(route('scraper.index'))
            ->assertSessionHas('error');

        $this->assertNotNull($news->fresh()->aiArticle);
    }
}
