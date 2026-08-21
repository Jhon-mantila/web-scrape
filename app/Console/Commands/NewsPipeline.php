<?php

namespace App\Console\Commands;

use App\ProcessScraping\Actions\RunNewsPipelineAction;
use Illuminate\Console\Command;

class NewsPipeline extends Command
{
    protected $signature = 'news:pipeline
                            {--limit=5 : Cuántas noticias procesar por paso}
                            {--mode=draft : draft|publish|schedule}
                            {--force : Reprocesa detalles e IA aunque ya estén procesados}
                            {--include-raw-html : Incluye raw_html en el prompt de IA}
                            {--skip-scrape : Omite el scrape del listado de noticias}
                            {--show-errors : Muestra errores de generación IA en consola}';

    protected $description = 'Pipeline completo: scrape → detalles → imagen → IA → WordPress';

    public function handle(RunNewsPipelineAction $action): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $mode = (string) $this->option('mode');

        $this->info("Pipeline iniciado (limit={$limit}, mode={$mode})");
        $this->newLine();

        $summary = $action->execute(
            $limit,
            $mode,
            (bool) $this->option('force'),
            (bool) $this->option('include-raw-html'),
            (bool) $this->option('skip-scrape'),
        );

        if (! $this->option('skip-scrape')) {
            $this->line('1. Scrape listado: '.$summary['scrape_news'].' URLs nuevas');
        }

        $this->line('2. Detalles — procesadas: '.$summary['details']['processed']
            .' | OK: '.$summary['details']['success']
            .' | fallidas: '.$summary['details']['failed']);

        $this->line('3. Imágenes — procesadas: '.$summary['images']['processed']
            .' | descargadas: '.$summary['images']['success']
            .' | sin imagen: '.$summary['images']['skipped']
            .' | fallidas: '.$summary['images']['failed']);

        $this->line('4. IA — procesadas: '.$summary['ai']['processed']
            .' | OK: '.$summary['ai']['success']
            .' | fallidas: '.$summary['ai']['failed']);

        $this->line('5. WordPress — procesadas: '.$summary['wordpress']['processed']
            .' | OK: '.$summary['wordpress']['success']
            .' | fallidas: '.$summary['wordpress']['failed']);

        if ($summary['ai']['failed'] > 0 && ($this->option('show-errors') || $this->output->isVerbose())) {
            $this->newLine();
            foreach ($summary['ai']['errors'] as $err) {
                $this->error('[news_id '.$err['news_id'].'] '.$err['message']);
            }
        }

        $this->newLine();
        $this->info('Pipeline finalizado.');

        return Command::SUCCESS;
    }
}
