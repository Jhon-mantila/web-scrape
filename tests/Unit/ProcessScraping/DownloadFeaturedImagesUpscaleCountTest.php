<?php

namespace Tests\Unit\ProcessScraping;

use App\Models\News;
use App\Models\NewsDetail;
use App\ProcessScraping\Actions\DownloadFeaturedImagesAction;
use App\ProcessScraping\Images\FeaturedImageUpscaler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DownloadFeaturedImagesUpscaleCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_real_esrgan_on_images_that_already_exist(): void
    {
        Storage::fake('public');
        config(['services.featured_image.watermark_enabled' => false]);

        $news = News::query()->create([
            'title' => 'Noticia con destacada',
            'url' => 'https://example.com/upscale-count',
            'source' => 'anime_news',
            'status_ia' => 'processed',
        ]);

        $path = "featured-images/{$news->id}.jpg";
        Storage::disk('public')->put($path, FeaturedImageUpscalerTest::jpegFixture(320, 240));

        NewsDetail::query()->create([
            'news_id' => $news->id,
            'status' => 'processed',
            'content_text' => 'Texto',
            'raw_html' => '<p>x</p>',
            'featured_image_path' => $path,
            'featured_image_source' => 'scraped',
        ]);

        $this->mock(FeaturedImageUpscaler::class, function ($mock) use ($path) {
            $mock->shouldReceive('enhanceUntilReady')
                ->andReturn(
                    ['path' => $path, 'enhanced' => true],
                    ['path' => $path, 'enhanced' => false],
                );
        });

        $action = $this->app->make(DownloadFeaturedImagesAction::class);
        $summary = $action->execute(5, true, [$news->id]);
        $action->ensureBatchReady([$news->id], true);

        $this->assertSame(0, $summary['downloaded']);
        $this->assertSame(0, $summary['generated']);
        $this->assertSame(1, $summary['upscaled']);
        $this->assertSame(1, $action->upscaledCount());
        $this->assertSame('scraped_upscaled', $news->detail()->first()?->featured_image_source);
    }

    public function test_pipeline_recapture_includes_ensure_batch_enhancements(): void
    {
        Storage::fake('public');
        config(['services.featured_image.watermark_enabled' => false]);

        $news = News::query()->create([
            'title' => 'Otra noticia',
            'url' => 'https://example.com/upscale-count-2',
            'source' => 'anime_news',
            'status_ia' => 'processed',
        ]);

        $path = "featured-images/{$news->id}.jpg";
        Storage::disk('public')->put($path, FeaturedImageUpscalerTest::jpegFixture(320, 240));

        NewsDetail::query()->create([
            'news_id' => $news->id,
            'status' => 'processed',
            'content_text' => 'Texto',
            'raw_html' => '<p>x</p>',
            'featured_image_path' => $path,
            'featured_image_source' => 'scraped',
        ]);

        $this->mock(FeaturedImageUpscaler::class, function ($mock) use ($path) {
            $mock->shouldReceive('enhanceUntilReady')
                ->andReturn(
                    ['path' => $path, 'enhanced' => true],
                    ['path' => $path, 'enhanced' => false],
                );
        });

        $action = $this->app->make(DownloadFeaturedImagesAction::class);
        $images = $action->execute(5, true, [$news->id]);
        $action->ensureBatchReady([$news->id], true);
        $images['upscaled'] = $action->upscaledCount();

        $this->assertSame(1, $images['upscaled']);
    }

    public function test_applies_esquina_logo_on_legacy_upscaled_images_without_brand(): void
    {
        Storage::fake('public');
        config(['services.featured_image.watermark_enabled' => true]);

        $news = News::query()->create([
            'title' => 'Ya mejorada sin logo',
            'url' => 'https://example.com/upscale-count-3',
            'source' => 'anime_news',
            'status_ia' => 'processed',
        ]);

        $path = "featured-images/{$news->id}.jpg";
        Storage::disk('public')->put($path, FeaturedImageUpscalerTest::jpegFixture(320, 240));

        NewsDetail::query()->create([
            'news_id' => $news->id,
            'status' => 'processed',
            'content_text' => 'Texto',
            'raw_html' => '<p>x</p>',
            'featured_image_path' => $path,
            'featured_image_source' => 'scraped_upscaled',
        ]);

        $this->mock(FeaturedImageUpscaler::class, function ($mock) use ($path) {
            $mock->shouldReceive('enhanceUntilReady')
                ->once()
                ->andReturn(['path' => $path, 'enhanced' => false]);
        });

        $this->mock(\App\ProcessScraping\Images\FeaturedImageWatermarker::class, function ($mock) use ($path) {
            $mock->shouldReceive('apply')->once()->andReturn($path);
            $mock->shouldReceive('normalizeDownloaded')->never();
        });

        $action = $this->app->make(DownloadFeaturedImagesAction::class);
        $action->execute(5, true, [$news->id]);

        $this->assertSame('upscaled_branded', $news->detail()->first()?->featured_image_source);
    }
}
