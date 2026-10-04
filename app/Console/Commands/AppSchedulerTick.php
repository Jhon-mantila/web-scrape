<?php

namespace App\Console\Commands;

use App\Scheduling\ScheduledTaskRunner;
use Illuminate\Console\Command;

class AppSchedulerTick extends Command
{
    protected $signature = 'app:scheduler-tick';

    protected $description = 'Latido del planificador: ejecuta las tareas programadas que correspondan';

    public function handle(ScheduledTaskRunner $runner): int
    {
        $runner->touchHeartbeat();

        $ran = $runner->runDueTasks();

        if ($ran > 0) {
            $this->info("Tareas ejecutadas: {$ran}");
        }

        return Command::SUCCESS;
    }
}
