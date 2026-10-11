<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class QueueMonitorController extends Controller
{
    public function index(Request $request): Response
    {
        $tab = $request->string('tab')->toString();
        if (! in_array($tab, ['pending', 'failed', 'batches'], true)) {
            $tab = 'pending';
        }

        $failedUuid = $request->string('failed')->toString();

        return Inertia::render('Settings/Queue/Index', [
            'tab' => $tab,
            'queueConnection' => config('queue.default'),
            'stats' => [
                'pending' => DB::table('jobs')->count(),
                'failed' => DB::table('failed_jobs')->count(),
                'batches' => DB::table('job_batches')->count(),
            ],
            'pendingJobs' => $this->pendingJobs(),
            'failedJobs' => $this->failedJobs(),
            'batches' => $this->batches(),
            'failedDetail' => $failedUuid !== ''
                ? $this->failedJobDetail($failedUuid)
                : null,
            'commands' => [
                'queue_work' => 'docker exec laravel_app php artisan queue:work --tries=3',
                'queue_listen' => 'docker exec laravel_app php artisan queue:listen --tries=3',
            ],
        ]);
    }

    public function retryFailed(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('success', 'Trabajo reencolado para reintento.');
    }

    public function retryAllFailed(): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        return back()->with('success', 'Todos los trabajos fallidos fueron reencolados.');
    }

    public function destroyFailed(string $uuid): RedirectResponse
    {
        Artisan::call('queue:forget', ['id' => $uuid]);

        return back()->with('success', 'Registro eliminado de failed_jobs.');
    }

    public function flushFailed(): RedirectResponse
    {
        Artisan::call('queue:flush');

        return back()->with('success', 'Tabla failed_jobs vaciada.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingJobs(): array
    {
        return DB::table('jobs')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(function (object $row): array {
                $summary = $this->summarizePayload($row->payload ?? null);

                return [
                    'id' => $row->id,
                    'queue' => $row->queue,
                    'attempts' => $row->attempts,
                    'display_name' => $summary['display_name'],
                    'job_class' => $summary['full_name'],
                    'reserved_at' => $this->formatUnix($row->reserved_at ?? null),
                    'available_at' => $this->formatUnix($row->available_at ?? null),
                    'created_at' => $this->formatUnix($row->created_at ?? null),
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function failedJobs(): array
    {
        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(100)
            ->get()
            ->map(function (object $row): array {
                $summary = $this->summarizePayload($row->payload ?? null);
                $exception = (string) ($row->exception ?? '');
                $firstLine = strtok($exception, "\n") ?: $exception;

                return [
                    'id' => $row->id,
                    'uuid' => $row->uuid,
                    'connection' => $row->connection,
                    'queue' => $row->queue,
                    'display_name' => $summary['display_name'],
                    'job_class' => $summary['full_name'],
                    'failed_at' => $row->failed_at
                        ? Carbon::parse($row->failed_at)->toIso8601String()
                        : null,
                    'exception_preview' => mb_substr($firstLine, 0, 200),
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function batches(): array
    {
        return DB::table('job_batches')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function (object $row): array {
                return [
                    'id' => $row->id,
                    'name' => $row->name,
                    'total_jobs' => $row->total_jobs,
                    'pending_jobs' => $row->pending_jobs,
                    'failed_jobs' => $row->failed_jobs,
                    'cancelled_at' => $this->formatUnix($row->cancelled_at ?? null),
                    'created_at' => $this->formatUnix($row->created_at ?? null),
                    'finished_at' => $this->formatUnix($row->finished_at ?? null),
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function failedJobDetail(string $uuid): ?array
    {
        $row = DB::table('failed_jobs')->where('uuid', $uuid)->first();

        if ($row === null) {
            return null;
        }

        $summary = $this->summarizePayload($row->payload ?? null);

        return [
            'uuid' => $row->uuid,
            'connection' => $row->connection,
            'queue' => $row->queue,
            'display_name' => $summary['display_name'],
            'job_class' => $summary['full_name'],
            'failed_at' => $row->failed_at
                ? Carbon::parse($row->failed_at)->toIso8601String()
                : null,
            'payload' => $this->prettyJson($row->payload ?? ''),
            'exception' => (string) ($row->exception ?? ''),
        ];
    }

    /**
     * @return array{display_name: string, full_name: string}
     */
    private function summarizePayload(?string $payload): array
    {
        if ($payload === null || $payload === '') {
            return [
                'display_name' => '—',
                'full_name' => '',
            ];
        }

        $data = json_decode($payload, true);
        if (! is_array($data)) {
            return [
                'display_name' => 'Payload inválido',
                'full_name' => '',
            ];
        }

        $full = (string) ($data['displayName'] ?? $data['job'] ?? 'Job');

        return [
            'display_name' => class_basename($full),
            'full_name' => $full,
        ];
    }

    private function formatUnix(mixed $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '' || (int) $timestamp === 0) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $timestamp)->toIso8601String();
    }

    private function prettyJson(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $raw;
        }

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $raw;
    }
}
