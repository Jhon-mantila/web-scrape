<?php

namespace App\Scheduling\Tasks;

use App\Scheduling\Contracts\ScheduledTaskHandler;
use App\VideoProcessing\Actions\ReleaseAbandonedVideoJobsAction;

class ReleaseAbandonedVideoJobsHandler implements ScheduledTaskHandler
{
    public function __construct(
        private readonly ReleaseAbandonedVideoJobsAction $action,
    ) {}

    public function key(): string
    {
        return 'video_processing_release_abandoned';
    }

    public function label(): string
    {
        return 'Liberar trabajos de video abandonados';
    }

    public function description(): string
    {
        return 'Devuelve a la cola los análisis/renders cuyo trabajador dejó de enviar heartbeat.';
    }

    public function sortOrder(): int
    {
        return 20;
    }

    public function run(): array
    {
        return $this->action->execute();
    }

    public function defaultConfig(): array
    {
        return [
            'enabled' => true,
            'frequency' => 'every_minute',
            'interval_hours' => 1,
            'daily_at' => '09:00',
            'timezone' => config('app.timezone', 'America/Bogota'),
        ];
    }
}
