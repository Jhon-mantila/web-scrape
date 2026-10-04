<?php

namespace App\Scheduling;

use App\Scheduling\Contracts\ScheduledTaskHandler;
use App\Scheduling\Tasks\SocialArticlesPublishQueueHandler;
use App\Scheduling\Tasks\SocialVideosPublishQueueHandler;
use Illuminate\Support\Collection;

class ScheduledTaskRegistry
{
    /**
     * @return list<ScheduledTaskHandler>
     */
    public function handlers(): array
    {
        return [
            app(SocialVideosPublishQueueHandler::class),
            app(SocialArticlesPublishQueueHandler::class),
        ];
    }

    public function get(string $key): ?ScheduledTaskHandler
    {
        foreach ($this->handlers() as $handler) {
            if ($handler->key() === $key) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, ScheduledTaskHandler>
     */
    public function collection(): Collection
    {
        return collect($this->handlers());
    }
}
