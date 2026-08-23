<?php

namespace App\Console\Commands;

use App\ProcessScraping\Actions\ResetNewsProcessingAction;
use Illuminate\Console\Command;

class ResetNewsProcessing extends Command
{
    protected $signature = 'news:reset-processing
                            {--force : Ejecuta sin pedir confirmación}
                            {--truncate : Limpieza TOTAL: TRUNCATE news, news_details, news_ai_articles + imágenes}
                            {--images-only : Solo borra imágenes destacadas (sin tocar BD de procesamiento)}
                            {--no-images : No borra archivos de imagen}
                            {--no-research : Conserva investigación (SearXNG) en BD}
                            {--no-ai : Conserva artículos IA en BD}';

    protected $description = 'Limpia imágenes y/o datos de procesamiento. Fuera del pipeline.';

    public function handle(ResetNewsProcessingAction $action): int
    {
        if ((bool) $this->option('truncate')) {
            return $this->runTruncate($action);
        }

        return $this->runSoftReset($action);
    }

    private function runTruncate(ResetNewsProcessingAction $action): int
    {
        $this->error('Modo TRUNCATE — limpieza COMPLETA');
        $this->line('Se borrará:');
        $this->line('  - Todos los archivos en storage/app/public/featured-images/');
        $this->line('  - TRUNCATE news_ai_articles');
        $this->line('  - TRUNCATE news_details');
        $this->line('  - TRUNCATE news');
        $this->newLine();
        $this->warn('No se borra el logo en storage/app/public/Logo/');
        $this->warn('Tendrás que volver a ejecutar scrape:news desde cero.');

        if (! $this->option('force') && ! $this->confirm('¿Confirmas el TRUNCATE completo?', false)) {
            $this->info('Cancelado.');

            return Command::SUCCESS;
        }

        $summary = $action->truncateAll();

        $this->newLine();
        $this->info('Truncate completado.');
        $this->line('Imágenes borradas: '.$summary['images_deleted']);
        $this->line('news eliminados: '.$summary['news_truncated']);
        $this->line('news_details eliminados: '.$summary['details_truncated']);
        $this->line('news_ai_articles eliminados: '.$summary['ai_articles_truncated']);
        $this->newLine();
        $this->line('Siguiente paso desde cero:');
        $this->line('  php artisan scrape:news');
        $this->line('  php artisan news:pipeline --limit=5 --include-raw-html --mode=draft');

        return Command::SUCCESS;
    }

    private function runSoftReset(ResetNewsProcessingAction $action): int
    {
        $imagesOnly = (bool) $this->option('images-only');
        $wipeImages = ! $this->option('no-images');
        $wipeResearch = ! $this->option('no-research') && ! $imagesOnly;
        $wipeAi = ! $this->option('no-ai') && ! $imagesOnly;

        if ($imagesOnly) {
            $wipeImages = true;
            $wipeResearch = false;
            $wipeAi = false;
        }

        $this->info('Modo reset suave — conserva news y news_details scrapeados.');
        $this->line('Acciones:');

        if ($wipeImages) {
            $this->line('  - Borrar archivos en storage/app/public/featured-images/');
            $this->line('  - Limpiar featured_image_path y featured_image_source en news_details');
        }

        if ($wipeResearch) {
            $this->line('  - Limpiar research_context, research_raw y researched_at en news_details');
        }

        if ($wipeAi) {
            $this->line('  - Borrar todos los registros de news_ai_articles');
            $this->line('  - Resetear news.status_ia a null');
        }

        if (! $wipeImages && ! $wipeResearch && ! $wipeAi) {
            $this->error('Nada que hacer. Quita --no-images, --no-research o --no-ai.');

            return Command::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('¿Continuar con el reset?', false)) {
            $this->info('Cancelado.');

            return Command::SUCCESS;
        }

        $summary = $action->execute($wipeImages, $wipeResearch, $wipeAi);

        $this->newLine();
        $this->info('Reset completado.');

        if ($wipeImages) {
            $this->line('Imágenes borradas: '.$summary['images_deleted']);
        }

        if ($wipeImages || $wipeResearch) {
            $this->line('Filas news_details actualizadas: '.$summary['details_updated']);
        }

        if ($wipeAi) {
            $this->line('Artículos IA eliminados: '.$summary['ai_articles_deleted']);
            $this->line('Noticias con status_ia reseteado: '.$summary['news_status_reset']);
        }

        $this->newLine();
        $this->line('Puedes volver a procesar con:');
        $this->line('  php artisan news:pipeline --limit=5 --skip-scrape --include-raw-html --mode=draft');
        $this->line('Limpieza total: php artisan news:reset-processing --truncate --force');

        return Command::SUCCESS;
    }
}
