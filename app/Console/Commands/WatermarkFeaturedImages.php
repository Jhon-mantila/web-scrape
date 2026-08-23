<?php

namespace App\Console\Commands;

use App\Models\News;
use App\ProcessScraping\Images\FeaturedImageWatermarker;
use Illuminate\Console\Command;

class WatermarkFeaturedImages extends Command
{
    protected $signature = 'news:watermark-images
                            {--limit=20 : Cuántas imágenes procesar}
                            {--force : Re-aplica aunque ya tengan marca de agua}';

    protected $description = 'Aplica el logo Esquina Anime a imágenes destacadas existentes';

    public function handle(FeaturedImageWatermarker $watermarker): int
    {
        if (! config('services.featured_image.watermark_enabled')) {
            $this->warn('Marca de agua desactivada. Usa FEATURED_IMAGE_WATERMARK_ENABLED=true');

            return Command::FAILURE;
        }

        $success = 0;
        $failed = 0;

        $items = News::query()
            ->whereHas('detail', fn ($q) => $q->whereNotNull('featured_image_path'))
            ->with('detail')
            ->orderByDesc('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($items as $news) {
            $path = $news->detail?->featured_image_path;

            if ($path === null || $path === '') {
                continue;
            }

            if ($watermarker->apply($path)) {
                $success++;
                $this->line("OK news_id={$news->id} → {$path}");
            } else {
                $failed++;
                $this->error("Fallo news_id={$news->id} → {$path}");
            }
        }

        $this->newLine();
        $this->info("Marca de agua aplicada: {$success} | fallidas: {$failed}");

        return Command::SUCCESS;
    }
}
