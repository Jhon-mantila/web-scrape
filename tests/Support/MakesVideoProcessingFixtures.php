<?php

namespace Tests\Support;

use App\Models\SocialPublication;
use App\Models\SocialVideo;
use App\Models\User;
use App\Models\VideoProcessingJob;
use App\Models\VideoWorker;
use App\SocialPublishing\Enums\PublicationStatus;
use App\VideoProcessing\Actions\QueueVideoSplitJobAction;
use App\VideoProcessing\Enums\VideoKind;
use Illuminate\Support\Facades\Storage;

trait MakesVideoProcessingFixtures
{
    protected string $workerPlainToken = 'worker-secret-token';

    protected function fakeVideoDisks(): void
    {
        Storage::fake('public');
        Storage::fake('local');
    }

    protected function makeOriginalVideo(User $user, string $contents = 'original-bytes'): SocialVideo
    {
        $path = 'social-videos/'.$user->id.'-original.mp4';
        Storage::disk('public')->put($path, $contents);

        $video = SocialVideo::query()->create([
            'user_id' => $user->id,
            'title' => 'Video largo',
            'video_path' => $path,
            'kind' => VideoKind::Original->value,
        ]);

        $video->publications()->create([
            'platform' => 'youtube',
            'status' => PublicationStatus::Draft,
        ]);

        return $video->fresh(['publications']);
    }

    protected function makeWorker(User $user, ?string $plainToken = null): VideoWorker
    {
        $token = $plainToken ?? $this->workerPlainToken;

        return VideoWorker::query()->create([
            'user_id' => $user->id,
            'name' => 'PC-test',
            'token_hash' => VideoWorker::hashToken($token),
            'is_enabled' => true,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function workerHeaders(?string $plainToken = null): array
    {
        return ['Authorization' => 'Bearer '.($plainToken ?? $this->workerPlainToken)];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function queueSplitJob(User $user, SocialVideo $video, array $overrides = []): VideoProcessingJob
    {
        return app(QueueVideoSplitJobAction::class)->execute($user, $video, array_merge([
            'target_clip_count' => 7,
            'min_duration_seconds' => 15,
            'max_duration_seconds' => 60,
            'mode' => 'smart',
            'framing' => 'smart_crop',
        ], $overrides));
    }
}
