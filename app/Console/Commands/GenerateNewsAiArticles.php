<?php

namespace App\Console\Commands;

use App\ProcessScraping\GenerateNewsAiArticleAction;
use Illuminate\Console\Command;

class GenerateNewsAiArticles extends Command
{
    protected $signature = 'news:generate-ai
                            {--limit=5 : Cuántas noticias procesar}
                            {--force : Incluye noticias ya marcadas status_ia=processed y sobrescribe el artículo IA}
                            {--include-raw-html : Añade raw_html al prompt además de content_text}
                            {--show-errors : Muestra el mensaje de cada fallo en consola}';

    protected $description = 'Genera artículos reescritos con Ollama y los guarda en news_ai_articles';

    public function handle(GenerateNewsAiArticleAction $action): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $summary = $action->execute(
            $limit,
            (bool) $this->option('force'),
            (bool) $this->option('include-raw-html'),
        );

        $this->info('Listo.');
        $this->line('Procesadas: '.$summary['processed']);
        $this->line('Correctas: '.$summary['success']);
        $this->line('Fallidas: '.$summary['failed']);

        if ($summary['failed'] > 0 && ($this->option('show-errors') || $this->output->isVerbose())) {
            $this->newLine();
            foreach ($summary['errors'] as $err) {
                $this->error('[news_id '.$err['news_id'].'] '.$err['message']);
            }
            $ollamaUrl = config('services.ollama.url');
            if (str_contains($ollamaUrl, 'localhost') || str_contains($ollamaUrl, '127.0.0.1')) {
                $this->warn(
                    'Si Laravel corre en Docker y Ollama en tu PC, localhost dentro del contenedor no es tu máquina. '
                    .'Prueba en .env: OLLAMA_URL=http://host.docker.internal:11434 (o la IP del host en Linux).'
                );
            }
        }

        return Command::SUCCESS;
    }
}
