<?php

namespace App\Console\Commands;

use App\SendWordpress\Actions\AttachWordpressFeaturedImagesAction;
use Illuminate\Console\Command;

class AttachWordpressFeaturedImages extends Command
{
    protected $signature = 'news:attach-wordpress-featured-images
                            {--limit=50 : Máximo de posts a intentar (adjuntar + sin imagen local + fallos)}
                            {--all : Revisar todos los enviados, sin tope de límite}
                            {--force : También posts que ya tienen imagen destacada en WP}';

    protected $description = 'Sube la imagen local y la asigna como destacada en posts WordPress ya creados';

    public function handle(AttachWordpressFeaturedImagesAction $action): int
    {
        $onlyMissing = ! (bool) $this->option('force');
        $limit = $this->option('all') ? 0 : max((int) $this->option('limit'), 1);

        if ($onlyMissing) {
            $this->line('Solo posts sin imagen destacada en WordPress (usa --force para reemplazar).');
        } else {
            $this->warn('Modo --force: se subirá media nueva aunque el post ya tenga destacada.');
        }

        $summary = $action->execute($limit, $onlyMissing);

        $this->newLine();
        $this->info('Adjuntar imágenes destacadas finalizado.');
        $this->line('Posts revisados: '.$summary['scanned']);
        $this->line('Destacadas asignadas: '.$summary['attached']);
        $this->line('Omitidos (ya tenían destacada en WP): '.$summary['skipped_has_featured']);
        $this->line('Sin imagen local: '.$summary['skipped_no_local_image']);
        $this->line('Fallidos: '.$summary['failed']);

        return Command::SUCCESS;
    }
}
