<?php

namespace App\Scheduling\Support;

use App\Models\ScheduledTask;
use Illuminate\Support\Carbon;

class ScheduledTaskTiming
{
    public function shouldRun(ScheduledTask $task): bool
    {
        if (! $task->enabled) {
            return false;
        }

        $tz = $task->timezone ?: config('app.timezone', 'UTC');
        $now = now($tz);
        $last = $task->last_run_at?->timezone($tz);

        return match ($task->frequency) {
            'every_minute' => $last === null || $last->diffInMinutes($now) >= 1,
            'every_n_hours' => $last === null
                || $last->diffInHours($now) >= max(1, (int) $task->interval_hours),
            'daily_at' => $this->shouldRunDailyAt($task, $now, $last),
            'once_at' => $this->shouldRunOnceAt($task, $now, $last),
            default => false,
        };
    }

    public function frequencyLabel(ScheduledTask $task): string
    {
        return match ($task->frequency) {
            'every_minute' => 'Cada minuto',
            'every_n_hours' => 'Cada '.max(1, (int) $task->interval_hours).' hora(s)',
            'daily_at' => 'Una vez al día a las '.$task->daily_at,
            'once_at' => $task->once_at !== null
                ? 'Una sola vez: '.$task->once_at->timezone($task->timezone)->format('Y-m-d H:i')
                : 'Una sola vez (sin fecha)',
            default => $task->frequency,
        };
    }

    public function nextRunHint(ScheduledTask $task): ?string
    {
        if (! $task->enabled) {
            return 'Desactivada';
        }

        $tz = $task->timezone ?: config('app.timezone', 'UTC');
        $now = now($tz);

        return match ($task->frequency) {
            'every_minute' => 'En el próximo tick (~1 min)',
            'every_n_hours' => $this->nextIntervalHint($task, $now),
            'daily_at' => $this->nextDailyHint($task, $now),
            'once_at' => $task->once_at?->timezone($tz)->toIso8601String(),
            default => null,
        };
    }

    private function shouldRunDailyAt(ScheduledTask $task, Carbon $now, ?Carbon $last): bool
    {
        [$hour, $minute] = $this->parseDailyAt($task->daily_at);
        $target = $now->copy()->setTime($hour, $minute, 0);

        if ($now->lt($target)) {
            return false;
        }

        return $last === null || $last->lt($target);
    }

    private function shouldRunOnceAt(ScheduledTask $task, Carbon $now, ?Carbon $last): bool
    {
        $once = $task->once_at?->timezone($task->timezone);

        if ($once === null || $now->lt($once)) {
            return false;
        }

        return $last === null || $last->lt($once);
    }

    private function nextIntervalHint(ScheduledTask $task, Carbon $now): string
    {
        $last = $task->last_run_at?->timezone($task->timezone);

        if ($last === null) {
            return 'En el próximo tick';
        }

        $next = $last->copy()->addHours(max(1, (int) $task->interval_hours));

        return $next->gt($now) ? $next->toIso8601String() : 'En el próximo tick';
    }

    private function nextDailyHint(ScheduledTask $task, Carbon $now): string
    {
        [$hour, $minute] = $this->parseDailyAt($task->daily_at);
        $target = $now->copy()->setTime($hour, $minute, 0);

        if ($now->lt($target)) {
            return $target->toIso8601String();
        }

        return $target->copy()->addDay()->toIso8601String();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseDailyAt(string $dailyAt): array
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $dailyAt, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [9, 0];
    }
}
