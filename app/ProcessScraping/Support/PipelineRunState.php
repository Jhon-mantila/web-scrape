<?php

namespace App\ProcessScraping\Support;

/**
 * Fachada sobre {@see BackgroundRunQueue} (cola por usuario).
 */
class PipelineRunState
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public static function start(int $userId, array $options): array
    {
        $steps = self::buildPipelineSteps($options);

        return BackgroundRunQueue::enqueue($userId, [
            'kind' => 'news_pipeline',
            'label' => self::pipelineLabel($options),
            'options' => $options,
            'job' => ['type' => 'news_pipeline', 'options' => $options],
            'steps' => $steps,
            'message' => 'Iniciando pipeline…',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    public static function startSocialPublish(
        int $userId,
        int $videoId,
        string $videoTitle,
        array $steps,
        ?array $publicationIds = null,
    ): array {
        return BackgroundRunQueue::enqueue($userId, [
            'kind' => 'social_publish',
            'label' => 'Publicación de video',
            'video_id' => $videoId,
            'video_title' => $videoTitle,
            'publication_ids' => $publicationIds,
            'job' => [
                'type' => 'social_publish',
                'video_id' => $videoId,
                'publication_ids' => $publicationIds,
            ],
            'steps' => $steps,
            'message' => 'Preparando envío a redes…',
        ]);
    }

    /**
     * @return array{runs: list<array<string, mixed>>, active_run_id: ?string, queued_count: int, running_count: int}
     */
    public static function snapshot(int $userId): array
    {
        return BackgroundRunQueue::snapshot($userId);
    }

    /** Compat: primer run en ejecución o el más reciente en cola. */
    public static function current(?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $snapshot = self::snapshot($userId);

        if ($snapshot['runs'] === []) {
            return null;
        }

        $active = collect($snapshot['runs'])->firstWhere('status', 'running');

        if ($active !== null) {
            return $active;
        }

        return $snapshot['runs'][array_key_last($snapshot['runs'])] ?? null;
    }

    public static function hasPending(int $userId): bool
    {
        $snapshot = self::snapshot($userId);

        return $snapshot['running_count'] > 0 || $snapshot['queued_count'] > 0;
    }

    public static function isRunning(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return self::snapshot($userId)['running_count'] > 0;
    }

    public static function beginStep(int $userId, string $stepKey, string $message): void
    {
        $runId = self::activeRunId($userId);

        if ($runId !== null) {
            BackgroundRunQueue::beginStep($userId, $runId, $stepKey, $message);
        }
    }

    public static function finishStep(int $userId, string $stepKey, string $detail): void
    {
        $runId = self::activeRunId($userId);

        if ($runId !== null) {
            BackgroundRunQueue::finishStep($userId, $runId, $stepKey, $detail);
        }
    }

    public static function skipStep(int $userId, string $stepKey, string $detail = 'Omitido'): void
    {
        $runId = self::activeRunId($userId);

        if ($runId !== null) {
            BackgroundRunQueue::skipStep($userId, $runId, $stepKey, $detail);
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public static function complete(int $userId, array $summary, string $message, bool $failed = false): void
    {
        $runId = self::activeRunId($userId);

        if ($runId !== null) {
            BackgroundRunQueue::completeRun($userId, $runId, $summary, $message, $failed);
        }
    }

    public static function fail(int $userId, string $message, ?string $runId = null): void
    {
        $runId ??= self::activeRunId($userId);

        if ($runId !== null) {
            BackgroundRunQueue::failRun($userId, $runId, $message);
        }
    }

    public static function dismiss(int $userId, ?string $runId = null): void
    {
        BackgroundRunQueue::dismissRun($userId, $runId);
    }

    public static function clear(int $userId): void
    {
        BackgroundRunQueue::clear($userId);
    }

    public static function activeRunId(int $userId): ?string
    {
        return self::snapshot($userId)['active_run_id'];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function pipelineLabel(array $options): string
    {
        if ($options['wordpress_only'] ?? false) {
            return 'Envío a WordPress';
        }

        if ($options['send_wordpress'] ?? false) {
            return 'Pipeline + WordPress';
        }

        return 'Pipeline de noticias';
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<array{key: string, label: string, status: string, detail: null}>
     */
    private static function buildPipelineSteps(array $options): array
    {
        $steps = [];

        if ($options['wordpress_only'] ?? false) {
            $steps[] = ['key' => 'wordpress', 'label' => 'WordPress', 'status' => 'pending', 'detail' => null];

            return $steps;
        }

        if (! ($options['skip_scrape'] ?? false)) {
            $steps[] = ['key' => 'scrape', 'label' => 'Scrape listado', 'status' => 'pending', 'detail' => null];
        }

        $steps[] = ['key' => 'details', 'label' => 'Detalles', 'status' => 'pending', 'detail' => null];
        $steps[] = ['key' => 'images', 'label' => 'Imágenes', 'status' => 'pending', 'detail' => null];

        if (! ($options['skip_research'] ?? false)) {
            $steps[] = ['key' => 'research', 'label' => 'Research', 'status' => 'pending', 'detail' => null];
        }

        $steps[] = ['key' => 'ai', 'label' => 'IA', 'status' => 'pending', 'detail' => null];

        if ($options['send_wordpress'] ?? false) {
            $steps[] = ['key' => 'wordpress', 'label' => 'WordPress', 'status' => 'pending', 'detail' => null];
        }

        return $steps;
    }
}
