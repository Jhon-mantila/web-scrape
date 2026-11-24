<?php

namespace App\VideoProcessing\Actions;

use App\Models\VideoProcessingJob;
use App\Models\VideoWorker;
use App\VideoProcessing\Enums\VideoProcessingJobStatus;

class ReleaseAbandonedVideoJobsAction
{
    /**
     * @return array{processed: int, published: int, failed: int, skipped: int, message: string}
     */
    public function execute(): array
    {
        $timeout = max(30, (int) config('video_processing.heartbeat_timeout_seconds', 120));
        $cutoff = now()->subSeconds($timeout);

        $jobs = VideoProcessingJob::query()
            ->whereIn('status', array_map(
                fn (VideoProcessingJobStatus $status) => $status->value,
                VideoProcessingJobStatus::claimedByWorker(),
            ))
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('heartbeat_at')
                    ->orWhere('heartbeat_at', '<', $cutoff);
            })
            ->get();

        $released = 0;

        foreach ($jobs as $job) {
            $workerId = $job->worker_id;

            $job->update([
                'status' => VideoProcessingJobStatus::Queued,
                'worker_id' => null,
                'claimed_at' => null,
                'progress_message' => 'Trabajo liberado: el trabajador dejó de responder.',
            ]);

            if ($workerId !== null) {
                VideoWorker::query()
                    ->where('id', $workerId)
                    ->where('current_job_id', $job->id)
                    ->update(['current_job_id' => null]);
            }

            $released++;
        }

        return [
            'processed' => $released,
            'published' => 0,
            'failed' => 0,
            'skipped' => 0,
            'message' => $released === 0
                ? 'No había trabajos abandonados.'
                : "Se liberaron {$released} trabajo(s) abandonado(s).",
        ];
    }
}
