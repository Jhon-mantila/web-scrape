<?php

namespace App\VideoProcessing\Actions;

use App\Models\SocialVideo;
use App\Models\User;
use App\Models\VideoProcessingJob;
use App\VideoProcessing\Enums\VideoProcessingJobStatus;
use InvalidArgumentException;

class CancelVideoSplitJobAction
{
    public function execute(User $user, SocialVideo $video, VideoProcessingJob $job): VideoProcessingJob
    {
        if ($job->user_id !== $user->id || $job->social_video_id !== $video->id) {
            throw new InvalidArgumentException('Este trabajo no pertenece a este video.');
        }

        if (! in_array($job->status, VideoProcessingJobStatus::active(), true)) {
            throw new InvalidArgumentException('Este trabajo ya no se puede cancelar.');
        }

        $job->update([
            'status' => VideoProcessingJobStatus::Cancelled,
            'progress_message' => 'Cancelado por el usuario.',
            'worker_id' => null,
            'finished_at' => now(),
        ]);

        return $job->fresh();
    }
}
