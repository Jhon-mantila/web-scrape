<?php

namespace App\Scheduling\Tasks;

use App\Scheduling\Contracts\ScheduledTaskHandler;
use App\SocialPublishing\Actions\PublishDueQueuedArticlePublicationsAction;

class SocialArticlesPublishQueueHandler implements ScheduledTaskHandler
{
    public function __construct(
        private readonly PublishDueQueuedArticlePublicationsAction $action,
    ) {}

    public function key(): string
    {
        return 'social_articles_publish_queue';
    }

    public function label(): string
    {
        return 'Cola de artículos (redes sociales)';
    }

    public function description(): string
    {
        return 'Publicará artículos de WordPress en cola cuando exista «Enviar automáticamente (app)» en Artículos (misma idea que videos).';
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
            'enabled' => false,
            'frequency' => 'every_n_hours',
            'interval_hours' => 3,
            'daily_at' => '10:00',
            'timezone' => config('app.timezone', 'America/Bogota'),
        ];
    }
}
