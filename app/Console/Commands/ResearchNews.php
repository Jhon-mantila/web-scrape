<?php

namespace App\Console\Commands;

use App\ProcessScraping\Actions\ResearchNewsAction;
use Illuminate\Console\Command;

class ResearchNews extends Command
{
    protected $signature = 'news:research
                            {--limit=5 : Cuántas noticias investigar}
                            {--force : Re-investiga aunque ya tengan research_context}';

    protected $description = 'Investiga noticias con SearXNG y guarda contexto en news_details';

    public function handle(ResearchNewsAction $action): int
    {
        $summary = $action->execute(
            max(1, (int) $this->option('limit')),
            (bool) $this->option('force'),
        );

        $this->info('Investigación finalizada.');
        $this->line('Procesadas: '.$summary['processed']);
        $this->line('Con resultados: '.$summary['success']);
        $this->line('Sin resultados: '.$summary['skipped']);
        $this->line('Fallidas: '.$summary['failed']);

        return Command::SUCCESS;
    }
}
