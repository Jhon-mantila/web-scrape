<?php

namespace App\VideoProcessing\Actions;

use App\Models\SocialVideo;
use App\Models\VideoProcessingJob;
use App\Models\VideoProcessingSegment;
use App\Models\VideoWorker;
use App\SocialPublishing\Enums\PublicationStatus;
use App\VideoProcessing\Enums\VideoKind;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class MaterializeVideoClipAction
{
    /**
     * @return array{video: SocialVideo, created: bool}
     */
    public function execute(
        VideoWorker $worker,
        VideoProcessingJob $job,
        int $index,
        UploadedFile $file,
    ): array {
        if (! $job->isClaimedBy($worker)) {
            throw new InvalidArgumentException('Este trabajo no está reclamado por este trabajador.');
        }

        /** @var VideoProcessingSegment|null $segment */
        $segment = $job->segments()->where('index', $index)->first();

        if ($segment === null) {
            throw new InvalidArgumentException('No hay un segmento aprobado con ese índice.');
        }

        if ($segment->output_video_id !== null) {
            $existing = SocialVideo::query()->find($segment->output_video_id);

            if ($existing instanceof SocialVideo) {
                return $this->replaceExistingClip($existing, $job, $segment, $index, $file, created: false);
            }
        }

        $parent = $job->video()->with('publications')->firstOrFail();

        if ($parent->isClip()) {
            throw new InvalidArgumentException('El video de origen no puede ser un clip.');
        }

        $existingSlot = SocialVideo::query()
            ->where('parent_video_id', $parent->id)
            ->where('clip_index', $index)
            ->first();

        if ($existingSlot instanceof SocialVideo) {
            return $this->replaceExistingClip($existingSlot, $job, $segment, $index, $file, created: false);
        }

        $relative = $this->storeClipFile($parent, $job, $index, $file);

        try {
            $clip = DB::transaction(function () use ($parent, $job, $segment, $index, $relative): SocialVideo {
                $clip = SocialVideo::query()->create([
                    'user_id' => $parent->user_id,
                    'parent_video_id' => $parent->id,
                    'kind' => VideoKind::Clip->value,
                    'source_job_id' => $job->id,
                    'clip_index' => $index,
                    'starts_at_ms' => $segment->start_ms,
                    'ends_at_ms' => $segment->end_ms,
                    'title' => $parent->title.' — clip '.($index + 1),
                    'video_path' => $relative,
                    'thumbnail_path' => null,
                    'notes' => $parent->notes,
                    'caption_ai_provider' => $parent->caption_ai_provider,
                    'caption_ollama_target' => $parent->caption_ollama_target,
                    'caption_ai_model' => $parent->caption_ai_model,
                ]);

                foreach ($parent->publications as $publication) {
                    $status = config("social.platforms.{$publication->platform}.coming_soon")
                        ? PublicationStatus::Unavailable
                        : PublicationStatus::Draft;

                    $clip->publications()->create([
                        'platform' => $publication->platform,
                        'status' => $status,
                        'caption_generated' => $publication->caption_generated,
                        'caption_edited' => $publication->caption_edited,
                    ]);
                }

                $segment->update(['output_video_id' => $clip->id]);

                return $clip->load('publications');
            });
        } catch (UniqueConstraintViolationException) {
            $existingSlot = SocialVideo::query()
                ->where('parent_video_id', $parent->id)
                ->where('clip_index', $index)
                ->firstOrFail();

            return $this->replaceExistingClip($existingSlot, $job, $segment, $index, $file, created: false);
        }

        return ['video' => $clip, 'created' => true];
    }

    /**
     * @return array{video: SocialVideo, created: bool}
     */
    private function replaceExistingClip(
        SocialVideo $clip,
        VideoProcessingJob $job,
        VideoProcessingSegment $segment,
        int $index,
        UploadedFile $file,
        bool $created,
    ): array {
        $parent = $job->video()->firstOrFail();
        $relative = $this->storeClipFile($parent, $job, $index, $file);
        $previousPath = $clip->video_path;

        $clip->update([
            'source_job_id' => $job->id,
            'starts_at_ms' => $segment->start_ms,
            'ends_at_ms' => $segment->end_ms,
            'video_path' => $relative,
            'title' => $parent->title.' — clip '.($index + 1),
        ]);

        $segment->update(['output_video_id' => $clip->id]);

        if (is_string($previousPath) && $previousPath !== '' && $previousPath !== $relative) {
            Storage::disk((string) config('video_processing.storage.clips_disk', 'public'))->delete($previousPath);
        }

        return ['video' => $clip->fresh(['publications']) ?? $clip, 'created' => $created];
    }

    private function storeClipFile(SocialVideo $parent, VideoProcessingJob $job, int $index, UploadedFile $file): string
    {
        $directory = trim((string) config('video_processing.storage.clips_directory', 'social-clips'), '/');
        $relative = $file->storeAs(
            $directory.'/'.$parent->id.'/'.$job->id,
            'clip-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT).'.mp4',
            (string) config('video_processing.storage.clips_disk', 'public'),
        );

        if (! is_string($relative) || $relative === '') {
            throw new InvalidArgumentException('No se pudo guardar el clip en disco.');
        }

        return $relative;
    }
}
