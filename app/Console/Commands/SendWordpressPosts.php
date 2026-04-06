<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\SendWordpress\Actions\SendPostToWordpressAction;

class SendWordpressPosts extends Command
{
    protected $signature = 'news:send-wordpress 
                            {--limit=5}
                            {--mode=draft : draft|publish|schedule}';

    protected $description = 'Envía artículos IA a WordPress';

    public function handle(SendPostToWordpressAction $action): int
    {
        $limit = max((int)$this->option('limit'), 1);
        $mode = $this->option('mode');

        $summary = $action->execute($limit, $mode);

        $this->info('Proceso finalizado');
        $this->line('Procesadas: '.$summary['processed']);
        $this->line('Exitosas: '.$summary['success']);
        $this->line('Fallidas: '.$summary['failed']);

        return Command::SUCCESS;
    }
}