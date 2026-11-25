<?php

namespace App\VideoProcessing\Actions;

use App\Models\SocialVideo;
use App\Models\User;
use App\Models\VideoProcessingJob;
use App\VideoProcessing\Enums\VideoFitFill;
use App\VideoProcessing\Enums\VideoFramingStrategy;
use App\VideoProcessing\Enums\VideoKind;
use App\VideoProcessing\Enums\VideoProcessingJobStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class QueueVideoSplitJobAction
{
    /**
     * @param  array{
     *     target_clip_count: int,
     *     min_duration_seconds: int,
     *     max_duration_seconds: int,
     *     mode: string,
     *     framing: string,
     *     fit_fill?: string,
     *     fit_template?: UploadedFile|null
     * }  $params
     */
    public function execute(User $user, SocialVideo $video, array $params): VideoProcessingJob
    {
        if ($video->user_id !== $user->id) {
            throw new InvalidArgumentException('Este video no te pertenece.');
        }

        if (($video->kind ?? VideoKind::Original->value) === VideoKind::Clip->value) {
            throw new InvalidArgumentException('No se puede dividir un clip derivado. Usa el video original.');
        }

        if ($video->parent_video_id !== null) {
            throw new InvalidArgumentException('No se puede dividir un clip derivado. Usa el video original.');
        }

        $hasActiveJob = VideoProcessingJob::query()
            ->where('social_video_id', $video->id)
            ->whereIn('status', array_map(
                fn (VideoProcessingJobStatus $status) => $status->value,
                VideoProcessingJobStatus::active(),
            ))
            ->exists();

        if ($hasActiveJob) {
            throw new InvalidArgumentException('Ya hay un trabajo de división en curso. Cancélalo o espera a que termine.');
        }

        $path = (string) $video->video_path;

        if ($path === '' || ! Storage::disk('public')->exists($path)) {
            throw new InvalidArgumentException('El archivo original no está en disco. No se volverá a pedir una subida desde aquí.');
        }

        $job = VideoProcessingJob::query()->create([
            'user_id' => $user->id,
            'social_video_id' => $video->id,
            'type' => 'intelligent_split',
            'status' => VideoProcessingJobStatus::Queued,
            'params' => [
                'target_clip_count' => $params['target_clip_count'],
                'min_duration_seconds' => $params['min_duration_seconds'],
                'max_duration_seconds' => $params['max_duration_seconds'],
                'mode' => $params['mode'],
                'framing' => $params['framing'],
                'fit_fill' => $params['framing'] === VideoFramingStrategy::Fit->value
                    ? ($params['fit_fill'] ?? VideoFitFill::Blur->value)
                    : null,
                'output_width' => (int) (config('video_processing.output.width') ?: 1080),
                'output_height' => (int) (config('video_processing.output.height') ?: 1920),
            ],
            'progress_percent' => 0,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $template = $params['fit_template'] ?? null;
        if ($params['framing'] === VideoFramingStrategy::Fit->value && $template instanceof UploadedFile) {
            $extension = strtolower($template->getClientOriginalExtension() ?: 'png');
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $extension = 'png';
            }
            $disk = (string) config('video_processing.storage.analysis_disk', 'local');
            $relative = $template->storeAs('video-jobs/'.$job->id, 'fit-template.'.$extension, $disk);
            if (is_string($relative) && $relative !== '') {
                $jobParams = $job->params;
                $jobParams['fit_template_path'] = $relative;
                $job->update(['params' => $jobParams]);
            }
        }

        return $job->fresh();
    }
}
