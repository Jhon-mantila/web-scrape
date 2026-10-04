<?php

namespace App\Scheduling\Tasks;

use App\Scheduling\Contracts\ScheduledTaskHandler;
use App\SocialPublishing\Actions\PublishDueQueuedSocialPublicationsAction;

class SocialVideosPublishQueueHandler implements ScheduledTaskHandler
{
    public function __construct(
        private readonly PublishDueQueuedSocialPublicationsAction $action,
    ) {}

    public function key(): string
    {
        return 'social_videos_publish_queue';
    }

    public function label(): string
    {
        return 'Cola de videos (redes sociales)';
    }

    public function description(): string
    {
        return 'Envía publicaciones de video con «Enviar automáticamente (app)» en la ficha de cada video.';
    }

    public function sortOrder(): int
    {
        return 10;
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
            'interval_hours' => 3,
            'daily_at' => '09:00',
            'timezone' => config('app.timezone', 'America/Bogota'),
        ];
    }
}
