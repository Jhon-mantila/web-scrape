<?php

namespace App\ProcessScraping\Actions;

use App\Models\News;
use App\ProcessScraping\Images\Generators\ComfyUIClient;
use App\ProcessScraping\Images\Generators\FeaturedImagePromptBuilder;
use App\ProcessScraping\Images\Generators\FluxSchnellWorkflow;
use App\ProcessScraping\Images\FeaturedImageWatermarker;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateFeaturedImagesAction
{
    public function __construct(
        private readonly ComfyUIClient $comfyui,
        private readonly FluxSchnellWorkflow $workflow,
        private readonly FeaturedImagePromptBuilder $promptBuilder,
        private readonly FeaturedImageWatermarker $watermarker,
    ) {}

    /**
     * @return array{processed: int, generated: int, skipped: int, failed: int}
     */
    public function execute(int $limit): array
    {
        if (! $this->comfyui->isEnabled()) {
            return ['processed' => 0, 'generated' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $processed = 0;
        $generated = 0;
        $skipped = 0;
        $failed = 0;

        $items = News::query()
            ->whereHas('detail', function ($q) {
                $q->where('status', 'processed')
                    ->whereNull('featured_image_path');
            })
            ->with('detail')
            ->orderBy('id')
            ->limit(max($limit, 1))
            ->get();

        foreach ($items as $news) {
            $processed++;

            $result = $this->generateForNews($news);

            match ($result) {
                'generated' => $generated++,
                'skipped' => $skipped++,
                default => $failed++,
            };
        }

        if ($generated > 0 && config('services.comfyui.free_memory_after_images')) {
            $this->comfyui->freeMemory();
        }

        return compact('processed', 'generated', 'skipped', 'failed');
    }

    public function generateForNews(News $news): string
    {
        if (! $this->comfyui->isEnabled()) {
            return 'skipped';
        }

        $news->loadMissing('detail');

        if ($news->detail?->featured_image_path) {
            return 'skipped';
        }

        if (! $this->comfyui->isReachable()) {
            Log::warning('comfyui: servicio no disponible', ['news_id' => $news->id]);

            return 'failed';
        }

        try {
            $prompt = $this->promptBuilder->build($news);
            $graph = $this->workflow->build($prompt);
            $queued = $this->comfyui->queuePrompt($graph);
            $images = $this->comfyui->waitForImages($queued['prompt_id']);

            if ($images === []) {
                return 'failed';
            }

            $binary = $this->comfyui->downloadImage($images[0]);
            $path = $this->storeImage($binary, $news->id);

            if ($path === null) {
                return 'failed';
            }

            $finalPath = $this->watermarker->apply($path) ?? $path;

            $news->detail?->update([
                'featured_image_path' => $finalPath,
                'featured_image_source' => 'generated',
            ]);

            Log::info('featured_image: generada con FLUX', [
                'news_id' => $news->id,
                'prompt_id' => $queued['prompt_id'],
                'path' => $path,
            ]);

            return 'generated';
        } catch (Throwable $e) {
            Log::warning('featured_image: fallo al generar con FLUX', [
                'news_id' => $news->id,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    private function storeImage(string $binary, int $newsId): ?string
    {
        if ($binary === '') {
            return null;
        }

        $path = "featured-images/{$newsId}.webp";

        Storage::disk('public')->put($path, $binary);

        return $path;
    }
}
