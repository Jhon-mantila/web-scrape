<?php

namespace App\Console\Commands;

use App\ProcessScraping\Actions\GenerateFeaturedImagesAction;
use Illuminate\Console\Command;

class GenerateFeaturedImages extends Command
{
    protected $signature = 'news:generate-images
                            {--limit=5 : Cuántas noticias procesar sin imagen}';

    protected $description = 'Genera imágenes destacadas con ComfyUI + FLUX Schnell (fallback)';

    public function handle(GenerateFeaturedImagesAction $action): int
    {
        if (! config('services.comfyui.enabled')) {
            $this->warn('ComfyUI desactivado. Activa COMFYUI_ENABLED=true en .env');

            return Command::FAILURE;
        }

        $summary = $action->execute(max(1, (int) $this->option('limit')));

        $this->info('Generación de imágenes finalizada.');
        $this->line('Procesadas: '.$summary['processed']);
        $this->line('Generadas: '.$summary['generated']);
        $this->line('Omitidas: '.$summary['skipped']);
        $this->line('Fallidas: '.$summary['failed']);

        if ($summary['failed'] > 0) {
            $this->newLine();
            $this->warn(
                'ComfyUI en WSL: usa COMFYUI_URL=http://comfyui.host:8188 y COMFYUI_HOST_IP con la IP de WSL (hostname -I).'
            );
        }

        return Command::SUCCESS;
    }
}
