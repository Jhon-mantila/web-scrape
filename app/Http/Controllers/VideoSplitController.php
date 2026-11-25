<?php

namespace App\Http\Controllers;

use App\Models\SocialVideo;
use App\Models\VideoProcessingJob;
use App\Models\VideoProcessingSegment;
use App\Models\VideoWorker;
use App\VideoProcessing\Actions\ApproveVideoSplitJobAction;
use App\VideoProcessing\Actions\CancelVideoSplitJobAction;
use App\VideoProcessing\Actions\QueueVideoSplitJobAction;
use App\VideoProcessing\Enums\VideoFitFill;
use App\VideoProcessing\Enums\VideoFramingStrategy;
use App\VideoProcessing\Enums\VideoProcessingJobStatus;
use App\VideoProcessing\Enums\VideoSplitMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class VideoSplitController extends Controller
{
    public function show(Request $request, SocialVideo $video): Response
    {
        abort_unless($video->user_id === $request->user()?->id, 403);
        abort_unless($video->isOriginal(), 403);

        $job = $video->processingJobs()
            ->with(['segments.outputVideo'])
            ->latest()
            ->first();

        $heartbeatSeconds = (int) (config('video_processing.heartbeat_timeout_seconds') ?: 120);

        return Inertia::render('Videos/Split', [
            'video' => $this->videoSummary($video),
            'job' => $job ? $this->jobPayload($job) : null,
            'workerOnline' => VideoWorker::query()
                ->where('is_enabled', true)
                ->where('last_seen_at', '>=', now()->subSeconds(max(20, (int) ($heartbeatSeconds / 4))))
                ->exists(),
            'defaults' => [
                'target_clip_count' => 7,
                'min_duration_seconds' => (int) config('video_processing.clip.min_duration_seconds', 15),
                'max_duration_seconds' => (int) config('video_processing.clip.max_duration_seconds', 60),
                'mode' => VideoSplitMode::Smart->value,
                'framing' => VideoFramingStrategy::SmartCrop->value,
                'fit_fill' => VideoFitFill::Blur->value,
            ],
            'modeOptions' => [
                ['value' => VideoSplitMode::Smart->value, 'label' => 'Inteligente', 'hint' => 'Escenas y silencios. Puede proponer menos clips que el objetivo.'],
                ['value' => VideoSplitMode::Scene->value, 'label' => 'Por escenas', 'hint' => 'Respeta los cortes de escena detectados.'],
                ['value' => VideoSplitMode::Manual->value, 'label' => 'Manual', 'hint' => 'El worker propone un punto de partida; tú ajustas la línea de tiempo.'],
            ],
            'framingOptions' => [
                ['value' => VideoFramingStrategy::SmartCrop->value, 'label' => 'Recorte inteligente', 'hint' => 'Enfoca el sujeto al pasar a 9:16.'],
                ['value' => VideoFramingStrategy::BlurredBackground->value, 'label' => 'Fondo desenfocado', 'hint' => 'Conserva el plano completo (útil si hay subtítulos incrustados).'],
                ['value' => VideoFramingStrategy::Fit->value, 'label' => 'Encajar completo', 'hint' => 'El video cabe entero. Eliges el fondo de las bandas: desenfocado, blanco o negro.'],
            ],
            'fitFillOptions' => [
                ['value' => VideoFitFill::Blur->value, 'label' => 'Desenfocado', 'hint' => 'El mismo video, borroso, rellena las bandas.'],
                ['value' => VideoFitFill::White->value, 'label' => 'Blanco', 'hint' => 'Bandas blancas.'],
                ['value' => VideoFitFill::Black->value, 'label' => 'Negro', 'hint' => 'Bandas negras.'],
            ],
        ]);
    }

    public function store(
        Request $request,
        SocialVideo $video,
        QueueVideoSplitJobAction $action,
    ): RedirectResponse {
        abort_unless($video->user_id === $request->user()?->id, 403);

        $validated = $request->validate([
            'target_clip_count' => [
                'required',
                'integer',
                'min:1',
                'max:'.(int) config('video_processing.clip.max_target_count', 30),
            ],
            'min_duration_seconds' => 'required|integer|min:1',
            'max_duration_seconds' => 'required|integer|gte:min_duration_seconds',
            'mode' => ['required', 'string', Rule::enum(VideoSplitMode::class)],
            'framing' => ['required', 'string', Rule::enum(VideoFramingStrategy::class)],
            'fit_fill' => ['nullable', 'string', Rule::enum(VideoFitFill::class)],
            'fit_template' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        $validated['fit_template'] = $request->file('fit_template');

        try {
            $action->execute($request->user(), $video, $validated);
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('videos.split.show', $video)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('videos.split.show', $video)
            ->with('success', 'Análisis en cola. Se procesará cuando un trabajador se conecte.');
    }

    public function approve(
        Request $request,
        SocialVideo $video,
        VideoProcessingJob $job,
        ApproveVideoSplitJobAction $action,
    ): RedirectResponse {
        abort_unless($video->user_id === $request->user()?->id, 403);
        abort_unless($job->social_video_id === $video->id, 404);

        $validated = $request->validate([
            'segments' => 'required|array|min:1',
            'segments.*.id' => 'nullable|integer',
            'segments.*.start_ms' => 'required|integer|min:0',
            'segments.*.end_ms' => 'required|integer|min:1',
        ]);

        try {
            $action->execute($request->user(), $video, $job, $validated['segments']);
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('videos.split.show', $video)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('videos.split.show', $video)
            ->with('success', 'Segmentación aprobada. El render quedará en cola hasta que el trabajador se conecte.');
    }

    public function cancel(
        Request $request,
        SocialVideo $video,
        VideoProcessingJob $job,
        CancelVideoSplitJobAction $action,
    ): RedirectResponse {
        abort_unless($video->user_id === $request->user()?->id, 403);
        abort_unless($job->social_video_id === $video->id, 404);

        try {
            $action->execute($request->user(), $video, $job);
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('videos.split.show', $video)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('videos.split.show', $video)
            ->with('success', 'Trabajo cancelado. Puedes lanzar un análisis nuevo.');
    }

    /**
     * @return array<string, mixed>
     */
    private function videoSummary(SocialVideo $video): array
    {
        $onDisk = is_string($video->video_path)
            && $video->video_path !== ''
            && Storage::disk('public')->exists($video->video_path);

        return [
            'id' => $video->id,
            'title' => $video->title,
            'is_clip' => $video->isClip(),
            'video_url' => $onDisk ? '/storage/'.ltrim($video->video_path, '/') : null,
            'video_missing' => ! $onDisk,
            'clips_count' => $video->clips()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jobPayload(VideoProcessingJob $job): array
    {
        $summary = $job->result_summary ?? [];

        return [
            'id' => $job->id,
            'status' => $job->status->value,
            'status_label' => $this->statusLabel($job->status),
            'is_active' => in_array($job->status, VideoProcessingJobStatus::active(), true),
            'needs_poll' => in_array($job->status, [
                VideoProcessingJobStatus::Queued,
                VideoProcessingJobStatus::Claimed,
                VideoProcessingJobStatus::Analyzing,
                VideoProcessingJobStatus::Processing,
                VideoProcessingJobStatus::Uploading,
            ], true),
            'progress_percent' => (int) ($job->progress_percent ?? 0),
            'progress_message' => $job->progress_message,
            'error_message' => $job->error_message,
            'params' => $job->params ?? [],
            'recommended_clip_count' => $summary['recommended_clip_count'] ?? null,
            'recommendation_reason' => $summary['recommendation_reason'] ?? null,
            'duration_ms' => $summary['duration_ms'] ?? null,
            'segments' => $job->segments->map(fn (VideoProcessingSegment $segment) => [
                'id' => $segment->id,
                'index' => $segment->index,
                'start_ms' => $segment->start_ms,
                'end_ms' => $segment->end_ms,
                'source' => $segment->source,
                'score' => $segment->score,
                'warnings' => $segment->warnings ?? [],
                'approved' => (bool) $segment->approved,
                'output_video_id' => $segment->output_video_id,
                'output_video' => $segment->outputVideo ? [
                    'id' => $segment->outputVideo->id,
                    'title' => $segment->outputVideo->title,
                ] : null,
            ])->values(),
        ];
    }

    private function statusLabel(VideoProcessingJobStatus $status): string
    {
        return match ($status) {
            VideoProcessingJobStatus::Queued => 'En cola (esperando al PC)',
            VideoProcessingJobStatus::Claimed => 'Tomado por el trabajador',
            VideoProcessingJobStatus::Analyzing => 'Analizando cortes',
            VideoProcessingJobStatus::AwaitingApproval => 'Revisa y aprueba los cortes',
            VideoProcessingJobStatus::Processing => 'Renderizando clips 9:16',
            VideoProcessingJobStatus::Uploading => 'Subiendo clips',
            VideoProcessingJobStatus::Completed => 'Listo',
            VideoProcessingJobStatus::Failed => 'Falló',
            VideoProcessingJobStatus::Cancelled => 'Cancelado',
        };
    }
}
