<?php

namespace App\Console\Commands;

use App\Scheduling\ScheduledTaskRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AppSchedulerTick extends Command
{
    protected $signature = 'app:scheduler-tick';

    protected $description = 'Latido del planificador: ejecuta las tareas programadas que correspondan';

    public function handle(ScheduledTaskRunner $runner): int
    {
        $runner->touchHeartbeat();

        $ran = $runner->runDueTasks();

        Log::info('scheduler_tick: latido', [
            'tasks_executed' => $ran,
            'daemon_active_hint' => 'schedule:work debe estar corriendo para ticks automáticos cada minuto',
        ]);

        if ($ran > 0) {
            $this->info("Tareas ejecutadas: {$ran}");
        }

        return Command::SUCCESS;
    }
}
