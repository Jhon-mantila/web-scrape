<?php

namespace App\Console\Commands;

use App\SendWordpress\Actions\SendPostToWordpressAction;
use Illuminate\Console\Command;

class SendWordpressPosts extends Command
{
    protected $signature = 'news:send-wordpress
                            {--limit=5 : Cuántos artículos enviar en esta ejecución}
                            {--mode=draft : draft|publish|schedule}';

    protected $description = 'Envía artículos IA a WordPress';

    public function handle(SendPostToWordpressAction $action): int
    {
        $limit = max((int) $this->option('limit'), 1);
        $mode = (string) $this->option('mode');

        if ($mode === 'schedule') {
            $max = config('services.wordpress.schedule_max_per_day');
            $intervalMax = config('services.wordpress.schedule_interval_hours');
            $intervalMin = config('services.wordpress.schedule_interval_min_hours');
            $this->info("Modo programación — máx. {$max} posts/día");
            $this->line('Intervalo entre posts: '.($intervalMin ? "{$intervalMin}-{$intervalMax}" : $intervalMax).' h');
        }

        $summary = $action->execute($limit, $mode);

        $this->info('Proceso finalizado');
        $this->line('Procesadas: '.$summary['processed']);
        $this->line('Exitosas: '.$summary['success']);
        $this->line('Fallidas: '.$summary['failed']);

        if ($summary['by_author'] !== []) {
            $this->newLine();
            $this->info('Por autor WordPress:');

            foreach ($summary['by_author'] as $author => $count) {
                $this->line("  {$author}: {$count}");
            }
        }

        if ($mode === 'schedule' && $summary['scheduled'] !== []) {
            $this->newLine();
            $this->info('Programaciones asignadas:');

            foreach ($summary['scheduled'] as $item) {
                $this->line("  news_id={$item['news_id']} → {$item['scheduled_at']} ({$item['author']})");
            }
        }

        return Command::SUCCESS;
    }
}
