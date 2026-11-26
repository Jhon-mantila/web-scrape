<?php

namespace App\Console\Commands;

use App\ProcessScraping\Images\FeaturedImageUpscaler;
use Illuminate\Console\Command;

class FetchFeaturedImageUpscalerModel extends Command
{
    protected $signature = 'news:fetch-upscaler-model';

    protected $description = 'Descarga realesr-general-x4v3.onnx (Real-ESRGAN ligero) para mejorar imágenes scrapeadas pequeñas';

    public function handle(FeaturedImageUpscaler $upscaler): int
    {
        $path = $upscaler->ensureModel();

        if ($path === null) {
            $this->error('No se pudo obtener el modelo. Revisa red, FEATURED_IMAGE_UPSCALE_MODEL_URL y laravel.log.');

            return Command::FAILURE;
        }

        $this->info('Modelo listo: '.$path);
        $this->line('Tamaño: '.(@filesize($path) ?: 0).' bytes');

        return Command::SUCCESS;
    }
}
