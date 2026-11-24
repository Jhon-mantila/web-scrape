<?php

namespace App\VideoProcessing\Actions;

use App\Models\VideoProcessingJob;
use App\Models\VideoWorker;
use App\VideoProcessing\Enums\VideoProcessingJobStatus;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class StoreJobAnalysisAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(VideoWorker $worker, VideoProcessingJob $job, array $payload): VideoProcessingJob
    {
        if (! $job->isClaimedBy($worker)) {
            throw new InvalidArgumentException('Este trabajo no está reclamado por este trabajador.');
        }

        $segments = $payload['segments'] ?? [];

        if (! is_array($segments) || $segments === []) {
            throw new InvalidArgumentException('El análisis debe incluir al menos un segmento propuesto.');
        }

        $job->segments()->delete();

        foreach ($segments as $row) {
            $job->segments()->create([
                'index' => (int) $row['index'],
                'start_ms' => (int) $row['start_ms'],
                'end_ms' => (int) $row['end_ms'],
                'source' => (string) ($row['source'] ?? 'smart'),
                'score' => isset($row['score']) ? (float) $row['score'] : null,
                'warnings' => $row['warnings'] ?? [],
                'approved' => false,
            ]);
        }

        $disk = (string) config('video_processing.storage.analysis_disk', 'local');
        $path = 'video-jobs/'.$job->id.'/analysis.json';
        Storage::disk($disk)->put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $job->update([
            'status' => VideoProcessingJobStatus::AwaitingApproval,
            'analysis_path' => $path,
            'progress_percent' => 100,
            'progress_message' => 'Análisis listo para revisión',
            'result_summary' => [
                'target_clip_count' => $payload['target_clip_count'] ?? $job->params['target_clip_count'] ?? null,
                'recommended_clip_count' => $payload['recommended_clip_count'] ?? count($segments),
                'recommendation_reason' => $payload['recommendation_reason'] ?? null,
                'duration_ms' => $payload['duration_ms'] ?? null,
            ],
            'worker_id' => null,
            'heartbeat_at' => now(),
        ]);

        $worker->update(['current_job_id' => null]);

        return $job->fresh(['segments']);
    }
}
