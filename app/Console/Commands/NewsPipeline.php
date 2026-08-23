<?php

namespace App\Console\Commands;

use App\ProcessScraping\Actions\RunNewsPipelineAction;
use Illuminate\Console\Command;

class NewsPipeline extends Command
{
    protected $signature = 'news:pipeline
                            {--limit=5 : Cuántas noticias procesar por paso}
                            {--mode=draft : draft|publish|schedule}
                            {--force : Reprocesa detalles, investigación e IA aunque ya estén procesados}
                            {--include-raw-html : Incluye raw_html en el prompt de IA}
                            {--skip-scrape : Omite el scrape del listado de noticias}
                            {--skip-research : Omite la investigación con SearXNG}
                            {--skip-generate : Omite la generación FLUX cuando no hay imagen del scrape}
                            {--show-errors : Muestra errores de generación IA en consola}';

    protected $description = 'Pipeline completo: scrape → detalles → imagen → investigación → IA → WordPress';

    public function handle(RunNewsPipelineAction $action): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $mode = (string) $this->option('mode');

        $this->info("Pipeline iniciado (limit={$limit}, mode={$mode})");
        $this->newLine();

        $startedAt = microtime(true);

        $summary = $action->execute(
            $limit,
            $mode,
            (bool) $this->option('force'),
            (bool) $this->option('include-raw-html'),
            (bool) $this->option('skip-scrape'),
            (bool) $this->option('skip-research'),
            (bool) $this->option('skip-generate'),
        );

        $timings = $summary['timings'] ?? [];
        $step = 1;

        if (! $this->option('skip-scrape')) {
            $this->line($this->stepLine(
                $step++,
                'Scrape listado: '.$summary['scrape_news'].' URLs nuevas',
                $timings['scrape'] ?? null,
            ));
        }

        $this->line($this->stepLine(
            $step++,
            'Detalles — procesadas: '.$summary['details']['processed']
                .' | OK: '.$summary['details']['success']
                .' | fallidas: '.$summary['details']['failed'],
            $timings['details'] ?? null,
        ));

        $this->line($this->stepLine(
            $step++,
            'Imágenes — procesadas: '.$summary['images']['processed']
                .' | descargadas: '.$summary['images']['downloaded']
                .' | generadas (FLUX): '.$summary['images']['generated']
                .' | sin imagen: '.$summary['images']['skipped']
                .' | fallidas: '.$summary['images']['failed'],
            $timings['images'] ?? null,
        ));

        if (! $this->option('skip-research')) {
            $this->line($this->stepLine(
                $step++,
                'Investigación — procesadas: '.$summary['research']['processed']
                    .' | OK: '.$summary['research']['success']
                    .' | sin resultados: '.$summary['research']['skipped']
                    .' | fallidas: '.$summary['research']['failed'],
                $timings['research'] ?? null,
            ));
        }

        $this->line($this->stepLine(
            $step++,
            'IA — procesadas: '.$summary['ai']['processed']
                .' | OK: '.$summary['ai']['success']
                .' | fallidas: '.$summary['ai']['failed'],
            $timings['ai'] ?? null,
        ));

        $wpLine = 'WordPress — procesadas: '.$summary['wordpress']['processed']
            .' | OK: '.$summary['wordpress']['success']
            .' | fallidas: '.$summary['wordpress']['failed'];

        if (($summary['wordpress']['by_author'] ?? []) !== []) {
            $parts = [];

            foreach ($summary['wordpress']['by_author'] as $author => $count) {
                $parts[] = "{$author}: {$count}";
            }

            $wpLine .= ' | autores: '.implode(', ', $parts);
        }

        $this->line($this->stepLine($step, $wpLine, $timings['wordpress'] ?? null));

        if ($summary['wordpress']['processed'] === 0 && $summary['ai']['success'] > 0) {
            $this->warn(
                'IA generó artículos pero WordPress no envió ninguno. '
                .'Vuelve a ejecutar; con el fix actual deberían marcarse como pendientes al regenerar.'
            );
        }

        if ($summary['ai']['failed'] > 0 && ($this->option('show-errors') || $this->output->isVerbose())) {
            $this->newLine();
            foreach ($summary['ai']['errors'] as $err) {
                $this->error('[news_id '.$err['news_id'].'] '.$err['message']);
            }
            $ollamaUrl = config('services.ollama.url');
            if (str_contains($ollamaUrl, 'host.docker.internal')) {
                $this->warn(
                    'Ollama en WSL: usa OLLAMA_URL=http://ollama.host:11434 y OLLAMA_HOST_IP con la IP de WSL (hostname -I).'
                );
            }
        }

        $this->newLine();
        $this->info('Pipeline finalizado.');
        $this->line('Tiempo total: '.$this->formatDuration(microtime(true) - $startedAt));

        return Command::SUCCESS;
    }

    private function stepLine(int $number, string $content, ?float $seconds): string
    {
        $line = $number.'. '.$content;

        if ($seconds !== null) {
            $line .= ' — '.$this->formatDuration($seconds);
        }

        return $line;
    }

    private function formatDuration(float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d h %d min %d seg', $hours, $minutes, $secs);
        }

        if ($minutes > 0) {
            return sprintf('%d min %d seg', $minutes, $secs);
        }

        return sprintf('%d seg', $secs);
    }
}
