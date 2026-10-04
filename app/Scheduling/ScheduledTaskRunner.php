<?php

namespace App\Scheduling;

use App\Models\ScheduledTask;
use App\Scheduling\Contracts\ScheduledTaskHandler;
use App\Scheduling\Support\ScheduledTaskTiming;
use Illuminate\Support\Facades\Cache;

class ScheduledTaskRunner
{
    public function __construct(
        private readonly ScheduledTaskRegistry $registry,
        private readonly ScheduledTaskTiming $timing,
    ) {}

    public function touchHeartbeat(): void
    {
        Cache::put('scheduler.heartbeat_at', now()->toIso8601String(), now()->addHours(6));
    }

    public function heartbeatAt(): ?\Illuminate\Support\Carbon
    {
        $raw = Cache::get('scheduler.heartbeat_at');

        return is_string($raw) && $raw !== '' ? \Illuminate\Support\Carbon::parse($raw) : null;
    }

    public function daemonActive(): bool
    {
        $heartbeat = $this->heartbeatAt();

        return $heartbeat !== null && $heartbeat->greaterThan(now()->subMinutes(2));
    }

    public function ensureTaskRow(ScheduledTaskHandler $handler): ScheduledTask
    {
        $defaults = $handler->defaultConfig();

        return ScheduledTask::query()->firstOrCreate(
            ['task_key' => $handler->key()],
            [
                'label' => $handler->label(),
                'description' => $handler->description(),
                'enabled' => $defaults['enabled'],
                'frequency' => $defaults['frequency'],
                'interval_hours' => $defaults['interval_hours'],
                'daily_at' => $defaults['daily_at'],
                'timezone' => $defaults['timezone'],
                'sort_order' => $handler->sortOrder(),
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tasksForUi(): array
    {
        $out = [];

        foreach ($this->registry->handlers() as $handler) {
            $task = $this->ensureTaskRow($handler);
            $out[] = $this->taskPayload($task, $handler);
        }

        usort($out, fn ($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));

        return $out;
    }

    public function runDueTasks(): int
    {
        $ran = 0;

        foreach ($this->registry->handlers() as $handler) {
            $task = $this->ensureTaskRow($handler);

            if (! $this->timing->shouldRun($task)) {
                continue;
            }

            $this->runTask($handler, $task);
            $ran++;
        }

        return $ran;
    }

    /**
     * @return array{processed: int, published: int, failed: int, skipped: int, message?: string}
     */
    public function runTaskNow(string $taskKey): array
    {
        $handler = $this->registry->get($taskKey);

        if ($handler === null) {
            throw new \InvalidArgumentException("Tarea desconocida: {$taskKey}");
        }

        $task = $this->ensureTaskRow($handler);

        return $this->runTask($handler, $task);
    }

    /**
     * @return array{processed: int, published: int, failed: int, skipped: int, message?: string}
     */
    private function runTask(ScheduledTaskHandler $handler, ScheduledTask $task): array
    {
        $summary = $handler->run();

        $task->update([
            'last_run_at' => now(),
            'last_run_summary' => $summary,
        ]);

        if ($task->frequency === 'once_at') {
            $task->update(['enabled' => false]);
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function taskPayload(ScheduledTask $task, ScheduledTaskHandler $handler): array
    {
        return [
            'task_key' => $task->task_key,
            'label' => $task->label ?: $handler->label(),
            'description' => $task->description ?: $handler->description(),
            'enabled' => $task->enabled,
            'frequency' => $task->frequency,
            'interval_hours' => $task->interval_hours,
            'daily_at' => $task->daily_at,
            'once_at' => $task->once_at?->format('Y-m-d\TH:i'),
            'timezone' => $task->timezone,
            'sort_order' => $task->sort_order,
            'frequency_label' => $this->timing->frequencyLabel($task),
            'next_run_hint' => $this->timing->nextRunHint($task),
            'last_run_at' => $task->last_run_at?->toIso8601String(),
            'last_run_summary' => $task->last_run_summary,
        ];
    }
}
