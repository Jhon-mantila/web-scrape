<?php

namespace Tests\Feature;

use App\Models\News;
use App\Models\NewsAiArticle;
use App\Models\NewsDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteSingleScraperNewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_news_detail_ai_and_row(): void
    {
        $user = User::factory()->create();

        $news = News::query()->create([
            'title' => 'Test',
            'url' => 'https://example.com/del',
            'source' => 'anime_news',
            'status_ia' => 'processed',
        ]);

        NewsDetail::query()->create([
            'news_id' => $news->id,
            'status' => 'processed',
            'content_text' => 'Texto',
            'raw_html' => '<p>x</p>',
        ]);

        NewsAiArticle::query()->create([
            'news_id' => $news->id,
            'generated_title' => 'Título IA',
            'body_html' => '<p>Cuerpo</p>',
            'sent_wordpress' => false,
        ]);

        $id = $news->id;

        $this->actingAs($user)
            ->delete(route('scraper.news.destroy', $news))
            ->assertRedirect();

        $this->assertNull(News::query()->find($id));
        $this->assertSame(0, NewsDetail::query()->where('news_id', $id)->count());
        $this->assertSame(0, NewsAiArticle::query()->where('news_id', $id)->count());
    }
}
