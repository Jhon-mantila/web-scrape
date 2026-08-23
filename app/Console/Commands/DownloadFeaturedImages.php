<?php

namespace App\Console\Commands;

use App\ProcessScraping\Actions\DownloadFeaturedImagesAction;
use Illuminate\Console\Command;

class DownloadFeaturedImages extends Command
{
    protected $signature = 'news:download-images
                            {--limit=20 : Cuántas noticias sin imagen procesar}
                            {--skip-generate : Solo scrape; no usar FLUX aunque COMFYUI_ENABLED=true}';

    protected $description = 'Descarga imágenes destacadas del scrape (og:image/HTML); FLUX solo como fallback';

    public function handle(DownloadFeaturedImagesAction $action): int
    {
        $skipGenerate = (bool) $this->option('skip-generate');

        if ($skipGenerate) {
            $this->line('Modo scrape únicamente (--skip-generate).');
        } elseif (config('services.comfyui.enabled')) {
            $this->line('Scrape primero; si no hay imagen, intentará FLUX (ComfyUI debe estar encendido en WSL).');
        } else {
            $this->line('Scrape únicamente (COMFYUI_ENABLED=false).');
        }

        $summary = $action->execute(max(1, (int) $this->option('limit')), $skipGenerate);

        $this->newLine();
        $this->info('Descarga de imágenes finalizada.');
        $this->line('Procesadas: '.$summary['processed']);
        $this->line('Descargadas (scrape): '.$summary['downloaded']);
        $this->line('Generadas (FLUX): '.$summary['generated']);
        $this->line('Omitidas (sin URL de imagen): '.$summary['skipped']);
        $this->line('Fallidas: '.$summary['failed']);

        if ($summary['failed'] > 0 && ! $skipGenerate && config('services.comfyui.enabled')) {
            $this->newLine();
            $this->warn(
                'Fallos con FLUX: enciende ComfyUI en WSL o usa --skip-generate para solo scrape. '
                .'Generar solo con FLUX: news:generate-images'
            );
        }

        return Command::SUCCESS;
    }
}
