<?php

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Models\VideoProcessingJob;
use App\Models\VideoWorker;
use App\VideoProcessing\Actions\ClaimVideoProcessingJobAction;
use App\VideoProcessing\Actions\MaterializeVideoClipAction;
use App\VideoProcessing\Actions\StoreJobAnalysisAction;
use App\VideoProcessing\Enums\VideoProcessingJobStatus;
use App\VideoProcessing\Enums\VideoSplitMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VideoWorkerJobController extends Controller
{
    public function heartbeat(Request $request): JsonResponse
    {
        $worker = $this->worker($request);
        $validated = $request->validate([
            'capabilities' => 'sometimes|array',
        ]);

        if (isset($validated['capabilities'])) {
            $worker->update(['capabilities' => $validated['capabilities']]);
        }

        return response()->json([
            'ok' => true,
            'worker_id' => $worker->id,
            'last_seen_at' => $worker->fresh()?->last_seen_at?->toIso8601String(),
        ]);
    }

    public function claim(Request $request, ClaimVideoProcessingJobAction $action): JsonResponse|Response
    {
        $job = $action->execute($this->worker($request));

        if ($job === null) {
            return response()->noContent();
        }

        return response()->json(['job' => $this->jobPayload($job)]);
    }

    public function source(Request $request, VideoProcessingJob $job): StreamedResponse|JsonResponse
    {
        $this->assertOwnsJob($request, $job);

        $video = $job->video;

        if ($video === null || ! Storage::disk('public')->exists((string) $video->video_path)) {
            return response()->json(['message' => 'El archivo original no está disponible.'], 404);
        }

        return Storage::disk('public')->response($video->video_path);
    }

    public function template(Request $request, VideoProcessingJob $job): StreamedResponse|JsonResponse
    {
        $this->assertOwnsJob($request, $job);

        $path = $job->params['fit_template_path'] ?? null;
        $disk = (string) config('video_processing.storage.analysis_disk', 'local');

        if (! is_string($path) || $path === '' || ! Storage::disk($disk)->exists($path)) {
            return response()->json(['message' => 'Este trabajo no tiene plantilla.'], 404);
        }

        return Storage::disk($disk)->response($path);
    }

    public function progress(Request $request, VideoProcessingJob $job): JsonResponse
    {
        $this->assertOwnsJob($request, $job);

        $validated = $request->validate([
            'progress_percent' => 'required|integer|min:0|max:100',
            'progress_message' => 'nullable|string|max:255',
            'status' => ['nullable', 'string', Rule::enum(VideoProcessingJobStatus::class)],
        ]);

        $updates = [
            'progress_percent' => $validated['progress_percent'],
            'progress_message' => $validated['progress_message'] ?? $job->progress_message,
            'heartbeat_at' => now(),
        ];

        if (isset($validated['status'])) {
            $updates['status'] = $validated['status'];
        }

        $job->update($updates);

        return response()->json(['job' => $this->jobPayload($job->fresh())]);
    }

    public function analysis(Request $request, VideoProcessingJob $job, StoreJobAnalysisAction $action): JsonResponse
    {
        $this->assertOwnsJob($request, $job);

        $validated = $request->validate([
            'duration_ms' => 'nullable|integer|min:1',
            'width' => 'nullable|integer|min:1',
            'height' => 'nullable|integer|min:1',
            'fps' => 'nullable|numeric',
            'target_clip_count' => 'nullable|integer|min:1',
            'recommended_clip_count' => 'nullable|integer|min:1',
            'recommendation_reason' => 'nullable|string|max:2000',
            'segments' => 'required|array|min:1',
            'segments.*.index' => 'required|integer|min:0',
            'segments.*.start_ms' => 'required|integer|min:0',
            'segments.*.end_ms' => 'required|integer|min:1',
            'segments.*.source' => ['nullable', 'string', Rule::enum(VideoSplitMode::class)],
            'segments.*.score' => 'nullable|numeric',
            'segments.*.warnings' => 'nullable|array',
        ]);

        try {
            $job = $action->execute($this->worker($request), $job, $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['job' => $this->jobPayload($job)]);
    }

    public function uploadClip(Request $request, VideoProcessingJob $job, int $index, MaterializeVideoClipAction $action): JsonResponse
    {
        $this->assertOwnsJob($request, $job);

        $validated = $request->validate([
            'clip' => 'required|file',
        ]);

        try {
            $result = $action->execute(
                $this->worker($request),
                $job,
                $index,
                $validated['clip'],
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            ['video_id' => $result['video']->id, 'created' => $result['created']],
            $result['created'] ? 201 : 200,
        );
    }

    public function fail(Request $request, VideoProcessingJob $job): JsonResponse
    {
        $this->assertOwnsJob($request, $job);

        $validated = $request->validate([
            'error_message' => 'required|string|max:5000',
        ]);

        $job->update([
            'status' => VideoProcessingJobStatus::Failed,
            'error_message' => $validated['error_message'],
            'finished_at' => now(),
            'worker_id' => null,
        ]);

        $this->worker($request)->update(['current_job_id' => null]);

        return response()->json(['job' => $this->jobPayload($job->fresh())]);
    }

    public function complete(Request $request, VideoProcessingJob $job): JsonResponse
    {
        $this->assertOwnsJob($request, $job);

        $job->update([
            'status' => VideoProcessingJobStatus::Completed,
            'progress_percent' => 100,
            'finished_at' => now(),
            'worker_id' => null,
        ]);

        $this->worker($request)->update(['current_job_id' => null]);

        return response()->json(['job' => $this->jobPayload($job->fresh())]);
    }

    private function worker(Request $request): VideoWorker
    {
        $worker = $request->attributes->get('videoWorker');

        abort_unless($worker instanceof VideoWorker, 401);

        return $worker;
    }

    private function assertOwnsJob(Request $request, VideoProcessingJob $job): void
    {
        abort_unless($job->isClaimedBy($this->worker($request)), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function jobPayload(VideoProcessingJob $job): array
    {
        $job->loadMissing('segments');

        return [
            'id' => $job->id,
            'status' => $job->status->value,
            'social_video_id' => $job->social_video_id,
            'params' => $job->params,
            'analysis_path' => $job->analysis_path,
            'stage' => filled($job->analysis_path) ? 'render' : 'analyze',
            'has_fit_template' => filled($job->params['fit_template_path'] ?? null),
            'progress_percent' => $job->progress_percent,
            'segments' => $job->segments->map(fn ($segment) => [
                'id' => $segment->id,
                'index' => $segment->index,
                'start_ms' => $segment->start_ms,
                'end_ms' => $segment->end_ms,
                'source' => $segment->source,
                'score' => $segment->score,
                'warnings' => $segment->warnings,
                'approved' => $segment->approved,
                'output_video_id' => $segment->output_video_id,
            ])->values()->all(),
        ];
    }
}
