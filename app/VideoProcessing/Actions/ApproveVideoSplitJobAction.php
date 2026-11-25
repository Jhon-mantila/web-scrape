<?php

namespace App\VideoProcessing\Actions;

use App\Models\SocialVideo;
use App\Models\User;
use App\Models\VideoProcessingJob;
use App\VideoProcessing\Enums\VideoProcessingJobStatus;
use App\VideoProcessing\Enums\VideoSplitMode;
use App\VideoProcessing\Support\ClipPlatformSelection;
use InvalidArgumentException;

class ApproveVideoSplitJobAction
{
    /**
     * @param  list<array{id?: int|null, start_ms: int, end_ms: int}>  $segments
     * @param  array{inherit_platforms?: bool, clip_platforms?: list<string>|null}  $platformParams
     */
    public function execute(
        User $user,
        SocialVideo $video,
        VideoProcessingJob $job,
        array $segments,
        array $platformParams = [],
    ): VideoProcessingJob {
        if ($job->user_id !== $user->id || $job->social_video_id !== $video->id) {
            throw new InvalidArgumentException('Este trabajo no pertenece a este video.');
        }

        if ($job->status !== VideoProcessingJobStatus::AwaitingApproval) {
            throw new InvalidArgumentException('El trabajo no está esperando aprobación.');
        }

        if ($segments === []) {
            throw new InvalidArgumentException('Debes aprobar al menos un segmento.');
        }

        usort($segments, fn (array $a, array $b) => ((int) $a['start_ms']) <=> ((int) $b['start_ms']));

        $previousEnd = null;
        $keptIds = [];
        $index = 0;

        foreach ($segments as $row) {
            $start = (int) $row['start_ms'];
            $end = (int) $row['end_ms'];

            if ($end <= $start) {
                throw new InvalidArgumentException('Cada clip debe terminar después de empezar.');
            }

            if ($previousEnd !== null && $start < $previousEnd) {
                throw new InvalidArgumentException('Los clips no pueden solaparse ni desordenar el video original.');
            }

            $segmentId = isset($row['id']) ? (int) $row['id'] : 0;
            $segment = $segmentId > 0
                ? $job->segments()->where('id', $segmentId)->first()
                : null;

            if ($segmentId > 0 && $segment === null) {
                throw new InvalidArgumentException('Hay un segmento que no pertenece a este trabajo.');
            }

            if ($segment === null) {
                $segment = $job->segments()->create([
                    'index' => $index,
                    'start_ms' => $start,
                    'end_ms' => $end,
                    'source' => VideoSplitMode::Manual->value,
                    'approved' => true,
                ]);
            } else {
                $segment->update([
                    'index' => $index,
                    'start_ms' => $start,
                    'end_ms' => $end,
                    'approved' => true,
                ]);
            }

            $keptIds[] = $segment->id;
            $previousEnd = $end;
            $index++;
        }

        $job->segments()->whereNotIn('id', $keptIds)->delete();

        $payload = [
            'status' => VideoProcessingJobStatus::Queued,
            'progress_percent' => 0,
            'progress_message' => 'Segmentación aprobada. En cola para renderizar.',
            'worker_id' => null,
        ];

        if (array_key_exists('inherit_platforms', $platformParams) || array_key_exists('clip_platforms', $platformParams)) {
            $params = $job->params ?? [];
            $payload['params'] = array_merge($params, ClipPlatformSelection::fromInput(
                $video,
                $platformParams['inherit_platforms'] ?? ($params['inherit_platforms'] ?? true),
                array_key_exists('clip_platforms', $platformParams)
                    ? $platformParams['clip_platforms']
                    : ($params['clip_platforms'] ?? null),
            ));
        }

        $job->update($payload);

        return $job->fresh(['segments']);
    }
}
