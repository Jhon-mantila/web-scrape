<?php

namespace App\Http\Controllers;

use App\Models\ScheduledTask;
use App\Scheduling\ScheduledTaskRegistry;
use App\Scheduling\ScheduledTaskRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ScheduledTasksController extends Controller
{
    public function index(ScheduledTaskRunner $runner): Response
    {
        return Inertia::render('Settings/Scheduler/Index', [
            'tasks' => $runner->tasksForUi(),
            'daemon' => [
                'active' => $runner->daemonActive(),
                'heartbeat_at' => $runner->heartbeatAt()?->toIso8601String(),
            ],
            'commands' => [
                'schedule_work' => 'docker exec laravel_app php artisan schedule:work',
                'schedule_run' => 'docker exec laravel_app php artisan schedule:run',
                'scheduler_tick' => 'docker exec laravel_app php artisan app:scheduler-tick',
                'social_publish_queued' => 'docker exec laravel_app php artisan social:publish-queued',
            ],
        ]);
    }

    public function update(
        Request $request,
        string $taskKey,
        ScheduledTaskRegistry $registry,
        ScheduledTaskRunner $runner,
    ): RedirectResponse {
        $handler = $registry->get($taskKey);

        if ($handler === null) {
            abort(404);
        }

        $validated = $request->validate([
            'enabled' => 'boolean',
            'frequency' => ['required', Rule::in(['every_minute', 'every_n_hours', 'daily_at', 'once_at'])],
            'interval_hours' => 'nullable|integer|min:1|max:168',
            'daily_at' => ['nullable', 'regex:/^\d{1,2}:\d{2}$/'],
            'once_at' => 'nullable|date',
            'timezone' => 'nullable|string|max:64',
        ]);

        $task = $runner->ensureTaskRow($handler);

        $task->update([
            'enabled' => $request->boolean('enabled'),
            'frequency' => $validated['frequency'],
            'interval_hours' => max(1, (int) ($validated['interval_hours'] ?? $task->interval_hours)),
            'daily_at' => $validated['daily_at'] ?? $task->daily_at,
            'once_at' => isset($validated['once_at']) && $validated['once_at'] !== ''
                ? \Illuminate\Support\Carbon::parse(
                    $validated['once_at'],
                    $validated['timezone'] ?? $task->timezone ?? config('app.timezone'),
                )
                : null,
            'timezone' => $validated['timezone'] ?? $task->timezone,
        ]);

        return back()->with('success', "Tarea «{$task->label}» guardada.");
    }

    public function runNow(string $taskKey, ScheduledTaskRunner $runner, ScheduledTaskRegistry $registry): RedirectResponse
    {
        if ($registry->get($taskKey) === null) {
            abort(404);
        }

        $summary = $runner->runTaskNow($taskKey);
        $task = ScheduledTask::query()->where('task_key', $taskKey)->first();

        $message = sprintf(
            '«%s»: %d procesadas · %d OK · %d fallidas.',
            $task?->label ?? $taskKey,
            $summary['processed'],
            $summary['published'],
            $summary['failed'],
        );

        if (isset($summary['message']) && $summary['processed'] === 0) {
            $message .= ' '.$summary['message'];
        }

        return back()->with(
            $summary['failed'] > 0 && $summary['published'] === 0 ? 'error' : 'success',
            $message,
        );
    }
}
