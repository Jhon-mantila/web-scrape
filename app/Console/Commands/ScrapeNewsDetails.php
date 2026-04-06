<?php

namespace App\Console\Commands;

use App\Scraper\Actions\ScrapeNewsDetailsAction;
use Illuminate\Console\Command;


class ScrapeNewsDetails extends Command
{
    protected $signature = 'scrape:news:details {--limit=30 : Cantidad de URLs a procesar} {--force : Reprocesa aunque ya esten procesadas}';

    protected $description = 'Entra a cada URL guardada en news y extrae contenido detallado';

    public function handle(ScrapeNewsDetailsAction $action): int
    {
        $summary = $action->execute(
            max((int) $this->option('limit'), 1),
            (bool) $this->option('force')
        );

        $this->info('Proceso finalizado');
        $this->line('Procesadas: ' . $summary['processed']);
        $this->line('Exitosas: ' . $summary['success']);
        $this->line('Fallidas: ' . $summary['failed']);

        return Command::SUCCESS;
    }
}
