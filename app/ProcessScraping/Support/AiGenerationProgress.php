<?php

declare(strict_types=1);

namespace App\ProcessScraping\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Progreso en vivo de generación IA (regenerar + paso IA del pipeline).
 * Se guarda en cache para polling desde la UI.
 */
final class AiGenerationProgress
{
    private const TTL_SECONDS = 3600;

    /**
     * @param  'staged'|'editorial_agent'|'legacy'  $motor
     */
    public static function begin(int $newsId, string $motor, ?int $pipelineUserId = null): void
    {
        self::put($newsId, [
            'news_id' => $newsId,
            'motor' => $motor,
            'motor_label' => self::motorLabel($motor),
            'status' => 'running',
            'current_step' => 0,
            'total_steps' => self::defaultTotalSteps($motor),
            'current_label' => 'Iniciando…',
            'started_at' => microtime(true),
            'elapsed_seconds' => 0.0,
            'tokens_total' => 0,
            'steps' => [],
            'pipeline_user_id' => $pipelineUserId,
            'error' => null,
        ]);
    }

    public static function pulse(int $newsId, int $step, int $total, string $label, int $tokensDelta = 0): void
    {
        $data = self::getRaw($newsId);

        if ($data === null) {
            return;
        }

        $tokensTotal = (int) ($data['tokens_total'] ?? 0) + max(0, $tokensDelta);
        $startedAt = (float) ($data['started_at'] ?? microtime(true));

        $steps = $data['steps'] ?? [];
        $steps[] = [
            'step' => $step,
            'total' => $total,
            'label' => $label,
            'tokens' => $tokensDelta > 0 ? $tokensDelta : null,
            'at' => now()->toIso8601String(),
        ];

        $payload = [
            ...$data,
            'current_step' => $step,
            'total_steps' => $total,
            'current_label' => $label,
            'tokens_total' => $tokensTotal,
            'elapsed_seconds' => round(microtime(true) - $startedAt, 1),
            'steps' => $steps,
        ];

        self::put($newsId, $payload);
        self::syncPipelineStep($payload);
    }

    public static function complete(int $newsId, int $tokensTotal, ?float $elapsedSeconds = null): void
    {
        $data = self::getRaw($newsId);

        if ($data === null) {
            return;
        }

        $startedAt = (float) ($data['started_at'] ?? microtime(true));
        $elapsed = $elapsedSeconds ?? round(microtime(true) - $startedAt, 2);

        self::put($newsId, [
            ...$data,
            'status' => 'completed',
            'tokens_total' => $tokensTotal > 0 ? $tokensTotal : (int) ($data['tokens_total'] ?? 0),
            'elapsed_seconds' => $elapsed,
            'current_label' => 'Completado',
        ]);

        self::syncPipelineStep(self::getRaw($newsId) ?? [], true);
    }

    public static function fail(int $newsId, string $message): void
    {
        $data = self::getRaw($newsId);

        if ($data === null) {
            return;
        }

        $startedAt = (float) ($data['started_at'] ?? microtime(true));

        self::put($newsId, [
            ...$data,
            'status' => 'failed',
            'error' => $message,
            'elapsed_seconds' => round(microtime(true) - $startedAt, 1),
            'current_label' => 'Falló',
        ]);

        self::syncPipelineStep(self::getRaw($newsId) ?? [], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function snapshot(int $newsId): ?array
    {
        $data = self::getRaw($newsId);

        if ($data === null) {
            return null;
        }

        if (($data['status'] ?? '') === 'running') {
            $startedAt = (float) ($data['started_at'] ?? microtime(true));
            $data['elapsed_seconds'] = round(microtime(true) - $startedAt, 1);
        }

        return $data;
    }

    public static function forget(int $newsId): void
    {
        Cache::forget(self::key($newsId));
    }

    private static function key(int $newsId): string
    {
        return "ai_gen_progress:news:{$newsId}";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function put(int $newsId, array $payload): void
    {
        Cache::put(self::key($newsId), $payload, self::TTL_SECONDS);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getRaw(int $newsId): ?array
    {
        $data = Cache::get(self::key($newsId));

        return is_array($data) ? $data : null;
    }

    /**
     * @param  'staged'|'editorial_agent'|'legacy'  $motor
     */
    private static function motorLabel(string $motor): string
    {
        return match ($motor) {
            'editorial_agent' => 'Agente editorial Neuron',
            'staged' => 'Ficha de hechos + Ollama',
            default => 'Ollama clásico',
        };
    }

    /**
     * @param  'staged'|'editorial_agent'|'legacy'  $motor
     */
    private static function defaultTotalSteps(string $motor): int
    {
        return match ($motor) {
            'editorial_agent' => 4,
            'staged' => 4,
            default => 3,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function syncPipelineStep(array $payload, bool $finished = false): void
    {
        $userId = $payload['pipeline_user_id'] ?? null;

        if ($userId === null) {
            return;
        }

        $newsId = (int) ($payload['news_id'] ?? 0);
        $motor = (string) ($payload['motor_label'] ?? '');
        $step = (int) ($payload['current_step'] ?? 0);
        $total = (int) ($payload['total_steps'] ?? 1);
        $label = (string) ($payload['current_label'] ?? '');
        $tokens = (int) ($payload['tokens_total'] ?? 0);
        $elapsed = (float) ($payload['elapsed_seconds'] ?? 0);

        $detail = "#{$newsId} · {$motor} · paso {$step}/{$total}: {$label}";

        if ($tokens > 0) {
            $detail .= " · {$tokens} tokens";
        }

        if ($elapsed > 0) {
            $detail .= ' · '.round($elapsed, 1).' s';
        }

        if ($finished && ($payload['status'] ?? '') === 'completed') {
            $detail = "#{$newsId} · IA OK · {$elapsed} s · {$tokens} tokens";
        }

        if ($finished && ($payload['status'] ?? '') === 'failed') {
            $detail = "#{$newsId} · ".((string) ($payload['error'] ?? 'Error IA'));
        }

        PipelineRunState::setRunningStepDetail((int) $userId, 'ai', $detail);
    }
}
