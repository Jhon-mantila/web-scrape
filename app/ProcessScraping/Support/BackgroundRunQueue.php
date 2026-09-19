<?php

namespace App\ProcessScraping\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BackgroundRunQueue
{
    private const TTL_SECONDS = 7200;

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function enqueue(int $userId, array $attributes): array
    {
        $bag = self::bag($userId);
        $hasRunner = self::hasRunning($bag);

        $run = self::withProgress([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'status' => $hasRunner ? 'queued' : 'running',
            'current_step' => null,
            'summary' => null,
            'started_at' => now()->toIso8601String(),
            'queued_at' => now()->toIso8601String(),
            'finished_at' => null,
            'updated_at' => now()->toIso8601String(),
            'progress_percent' => 0,
            ...$attributes,
        ]);

        if ($run['status'] === 'queued') {
            $queuePosition = count(array_filter(
                $bag['runs'],
                fn (array $item) => ($item['status'] ?? '') === 'queued',
            )) + 1;
            $run['queue_position'] = $queuePosition;
            $run['message'] = "En cola (posición {$queuePosition})…";
        }

        $bag['runs'][] = $run;
        self::saveBag($userId, $bag);

        return $run;
    }

    /**
     * @return array{runs: list<array<string, mixed>>, active_run_id: ?string, queued_count: int, running_count: int}
     */
    public static function snapshot(int $userId): array
    {
        $bag = self::bag($userId);
        $runs = array_map(fn (array $run) => self::withProgress($run), $bag['runs']);

        $active = collect($runs)->firstWhere('status', 'running');

        return [
            'runs' => $runs,
            'active_run_id' => $active['id'] ?? null,
            'queued_count' => collect($runs)->where('status', 'queued')->count(),
            'running_count' => collect($runs)->where('status', 'running')->count(),
        ];
    }

    public static function find(int $userId, string $runId): ?array
    {
        foreach (self::bag($userId)['runs'] as $run) {
            if (($run['id'] ?? '') === $runId) {
                return self::withProgress($run);
            }
        }

        return null;
    }

    /**
     * Toma el siguiente ítem para ejecutar (running existente o promueve queued).
     *
     * @return array<string, mixed>|null
     */
    public static function claimForExecution(int $userId): ?array
    {
        $bag = self::bag($userId);
        $changed = false;

        foreach ($bag['runs'] as $index => $run) {
            if (($run['status'] ?? '') === 'running') {
                return self::withProgress($bag['runs'][$index]);
            }
        }

        foreach ($bag['runs'] as $index => $run) {
            if (($run['status'] ?? '') !== 'queued') {
                continue;
            }

            $bag['runs'][$index]['status'] = 'running';
            $bag['runs'][$index]['started_at'] = now()->toIso8601String();
            $bag['runs'][$index]['message'] = 'Iniciando…';
            $bag['runs'][$index]['updated_at'] = now()->toIso8601String();
            unset($bag['runs'][$index]['queue_position']);
            $changed = true;

            if ($changed) {
                self::saveBag($userId, $bag);
            }

            return self::withProgress($bag['runs'][$index]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public static function mergeRun(int $userId, string $runId, array $patch): void
    {
        $bag = self::bag($userId);

        foreach ($bag['runs'] as $index => $run) {
            if (($run['id'] ?? '') !== $runId) {
                continue;
            }

            $bag['runs'][$index] = [...$run, ...$patch, 'updated_at' => now()->toIso8601String()];
            self::saveBag($userId, $bag);

            return;
        }
    }

    public static function beginStep(int $userId, string $runId, string $stepKey, string $message): void
    {
        $run = self::find($userId, $runId);

        if ($run === null) {
            return;
        }

        $steps = $run['steps'] ?? [];

        foreach ($steps as $index => $step) {
            if (($step['key'] ?? '') === $stepKey) {
                $steps[$index]['status'] = 'running';
                $steps[$index]['detail'] = null;
            } elseif (($step['status'] ?? '') === 'running') {
                $steps[$index]['status'] = 'done';
            }
        }

        self::mergeRun($userId, $runId, [
            'current_step' => $stepKey,
            'steps' => $steps,
            'message' => $message,
        ]);
    }

    public static function finishStep(int $userId, string $runId, string $stepKey, string $detail): void
    {
        $run = self::find($userId, $runId);

        if ($run === null) {
            return;
        }

        $steps = $run['steps'] ?? [];

        foreach ($steps as $index => $step) {
            if (($step['key'] ?? '') === $stepKey) {
                $steps[$index]['status'] = 'done';
                $steps[$index]['detail'] = $detail;
            }
        }

        self::mergeRun($userId, $runId, [
            'steps' => $steps,
            'message' => $detail,
        ]);
    }

    public static function skipStep(int $userId, string $runId, string $stepKey, string $detail = 'Omitido'): void
    {
        $run = self::find($userId, $runId);

        if ($run === null) {
            return;
        }

        $steps = $run['steps'] ?? [];

        foreach ($steps as $index => $step) {
            if (($step['key'] ?? '') === $stepKey) {
                $steps[$index]['status'] = 'skipped';
                $steps[$index]['detail'] = $detail;
            }
        }

        self::mergeRun($userId, $runId, ['steps' => $steps]);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public static function completeRun(int $userId, string $runId, array $summary, string $message, bool $failed = false): void
    {
        $run = self::find($userId, $runId);

        if ($run === null) {
            return;
        }

        $steps = $run['steps'] ?? [];

        foreach ($steps as $index => $step) {
            if (($step['status'] ?? '') === 'running') {
                $steps[$index]['status'] = 'done';
            }
        }

        self::mergeRun($userId, $runId, [
            'status' => $failed ? 'failed' : 'completed',
            'steps' => $steps,
            'current_step' => null,
            'summary' => $summary,
            'message' => $message,
            'finished_at' => now()->toIso8601String(),
        ]);

        self::reindexQueuePositions($userId);
    }

    public static function failRun(int $userId, string $runId, string $message): void
    {
        self::mergeRun($userId, $runId, [
            'status' => 'failed',
            'message' => $message,
            'finished_at' => now()->toIso8601String(),
        ]);

        self::reindexQueuePositions($userId);
    }

    public static function dismissRun(int $userId, ?string $runId = null): void
    {
        $bag = self::bag($userId);

        if ($runId === null) {
            $bag['runs'] = array_values(array_filter(
                $bag['runs'],
                fn (array $run) => in_array($run['status'] ?? '', ['running', 'queued'], true),
            ));
        } else {
            $bag['runs'] = array_values(array_filter(
                $bag['runs'],
                fn (array $run) => ($run['id'] ?? '') !== $runId,
            ));
        }

        self::saveBag($userId, $bag);
    }

    public static function clear(int $userId): void
    {
        Cache::forget(self::cacheKey($userId));
    }

    /**
     * @return array{runs: list<array<string, mixed>>}
     */
    private static function bag(int $userId): array
    {
        $bag = Cache::get(self::cacheKey($userId));

        if (! is_array($bag) || ! isset($bag['runs']) || ! is_array($bag['runs'])) {
            return ['runs' => []];
        }

        return $bag;
    }

    /**
     * @param  array{runs: list<array<string, mixed>>}  $bag
     */
    private static function saveBag(int $userId, array $bag): void
    {
        Cache::put(self::cacheKey($userId), $bag, self::TTL_SECONDS);
    }

    /**
     * @param  array{runs: list<array<string, mixed>>}  $bag
     */
    private static function hasRunning(array $bag): bool
    {
        foreach ($bag['runs'] as $run) {
            if (($run['status'] ?? '') === 'running') {
                return true;
            }
        }

        return false;
    }

    private static function reindexQueuePositions(int $userId): void
    {
        $bag = self::bag($userId);
        $position = 1;

        foreach ($bag['runs'] as $index => $run) {
            if (($run['status'] ?? '') !== 'queued') {
                continue;
            }

            $bag['runs'][$index]['queue_position'] = $position;
            $bag['runs'][$index]['message'] = "En cola (posición {$position})…";
            $position++;
        }

        self::saveBag($userId, $bag);
    }

    private static function cacheKey(int $userId): string
    {
        return "background_runs:user:{$userId}";
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private static function withProgress(array $run): array
    {
        $steps = $run['steps'] ?? [];
        $status = $run['status'] ?? 'pending';

        if ($status === 'queued') {
            $run['progress_percent'] = 0;

            return $run;
        }

        if ($steps === []) {
            $run['progress_percent'] = $status === 'completed' ? 100 : ($status === 'running' ? 1 : 0);

            return $run;
        }

        $total = count($steps);
        $weight = 0.0;

        foreach ($steps as $step) {
            $stepStatus = $step['status'] ?? 'pending';

            if ($stepStatus === 'done' || $stepStatus === 'skipped') {
                $weight += 1.0;
            } elseif ($stepStatus === 'running') {
                $weight += 0.45;
            }
        }

        if ($status === 'completed') {
            $run['progress_percent'] = 100;
        } elseif ($status === 'failed') {
            $run['progress_percent'] = (int) min(99, round(($weight / $total) * 100));
        } else {
            $run['progress_percent'] = (int) min(99, max(1, round(($weight / $total) * 100)));
        }

        return $run;
    }
}
