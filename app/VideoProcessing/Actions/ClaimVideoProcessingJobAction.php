<?php

namespace App\VideoProcessing\Actions;

use App\Models\VideoProcessingJob;
use App\Models\VideoWorker;
use App\VideoProcessing\Enums\VideoProcessingJobStatus;
use Illuminate\Support\Facades\DB;

class ClaimVideoProcessingJobAction
{
    public function execute(VideoWorker $worker): ?VideoProcessingJob
    {
        return DB::transaction(function () use ($worker): ?VideoProcessingJob {
            $job = VideoProcessingJob::query()
                ->where('status', VideoProcessingJobStatus::Queued)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($job === null) {
                return null;
            }

            $job->update([
                'status' => VideoProcessingJobStatus::Claimed,
                'worker_id' => $worker->id,
                'claimed_at' => now(),
                'heartbeat_at' => now(),
                'started_at' => $job->started_at ?? now(),
                'attempts' => $job->attempts + 1,
                'error_message' => null,
            ]);

            $worker->update(['current_job_id' => $job->id]);

            return $job->fresh();
        });
    }
}
