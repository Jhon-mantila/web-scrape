<?php

namespace Tests\Feature\VideoProcessing;

use App\Models\SocialVideo;
use App\Models\User;
use App\Models\VideoProcessingJob;
use App\Models\VideoProcessingSegment;
use App\VideoProcessing\Enums\VideoKind;
use App\VideoProcessing\Enums\VideoProcessingJobStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesVideoProcessingFixtures;
use Tests\TestCase;

class SplitPageTest extends TestCase
{
    use MakesVideoProcessingFixtures;
    use RefreshDatabase;

    public function test_owner_can_open_split_page_for_original_video(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $video = $this->makeOriginalVideo($user);

        $this->actingAs($user)
            ->get(route('videos.split.show', $video))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Videos/Split')
                ->where('video.id', $video->id)
                ->where('video.is_clip', false)
                ->where('job', null)
                ->has('defaults')
                ->has('modeOptions')
                ->has('framingOptions')
                ->has('fitFillOptions')
                ->has('workerOnline')
                ->has('platformOptions')
                ->where('defaults.fit_fill', 'blur')
                ->where('defaults.inherit_platforms', true)
                ->where('defaults.clip_platforms.0', 'youtube'));
    }

    public function test_cannot_open_split_page_for_a_clip(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $original = $this->makeOriginalVideo($user);
        $clip = SocialVideo::query()->create([
            'user_id' => $user->id,
            'parent_video_id' => $original->id,
            'kind' => VideoKind::Clip->value,
            'clip_index' => 0,
            'title' => 'Clip 1',
            'video_path' => 'social-clips/1/1/clip-00.mp4',
        ]);

        $this->actingAs($user)
            ->get(route('videos.split.show', $clip))
            ->assertForbidden();
    }

    public function test_queueing_split_redirects_to_split_page(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $video = $this->makeOriginalVideo($user);

        $this->actingAs($user)
            ->post(route('videos.split.store', $video), [
                'target_clip_count' => 4,
                'min_duration_seconds' => 15,
                'max_duration_seconds' => 60,
                'mode' => 'smart',
                'framing' => 'blurred_background',
            ])
            ->assertRedirect(route('videos.split.show', $video));

        $this->actingAs($user)
            ->get(route('videos.split.show', $video))
            ->assertInertia(fn ($page) => $page
                ->component('Videos/Split')
                ->where('job.status', 'queued')
                ->where('job.params.framing', 'blurred_background'));
    }

    public function test_cannot_queue_second_active_split_job(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $video = $this->makeOriginalVideo($user);
        $this->queueSplitJob($user, $video);

        $this->actingAs($user)
            ->from(route('videos.split.show', $video))
            ->post(route('videos.split.store', $video), [
                'target_clip_count' => 3,
                'min_duration_seconds' => 15,
                'max_duration_seconds' => 60,
                'mode' => 'scene',
                'framing' => 'fit',
            ])
            ->assertRedirect(route('videos.split.show', $video))
            ->assertSessionHas('error');

        $this->assertSame(1, VideoProcessingJob::query()->count());
    }

    public function test_user_can_cancel_active_job_and_queue_again(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $video = $this->makeOriginalVideo($user);
        $job = $this->queueSplitJob($user, $video);

        $this->actingAs($user)
            ->post(route('videos.split.cancel', [$video, $job]))
            ->assertRedirect(route('videos.split.show', $video));

        $this->assertSame(VideoProcessingJobStatus::Cancelled, $job->fresh()->status);

        $this->actingAs($user)
            ->post(route('videos.split.store', $video), [
                'target_clip_count' => 2,
                'min_duration_seconds' => 15,
                'max_duration_seconds' => 60,
                'mode' => 'smart',
                'framing' => 'smart_crop',
            ])
            ->assertRedirect(route('videos.split.show', $video));

        $this->assertSame(2, VideoProcessingJob::query()->count());
    }

    public function test_approve_can_create_a_manual_segment_without_id(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $video = $this->makeOriginalVideo($user);
        $job = $this->queueSplitJob($user, $video);
        $job->update(['status' => VideoProcessingJobStatus::AwaitingApproval]);

        $existing = VideoProcessingSegment::query()->create([
            'job_id' => $job->id,
            'index' => 0,
            'start_ms' => 0,
            'end_ms' => 20000,
            'source' => 'smart',
            'approved' => false,
        ]);

        $this->actingAs($user)
            ->post(route('videos.split.approve', [$video, $job]), [
                'segments' => [
                    ['id' => $existing->id, 'start_ms' => 0, 'end_ms' => 18000],
                    ['start_ms' => 18000, 'end_ms' => 40000],
                ],
            ])
            ->assertRedirect(route('videos.split.show', $video));

        $job->refresh();
        $this->assertSame(VideoProcessingJobStatus::Queued, $job->status);
        $this->assertSame(2, $job->segments()->count());
        $manual = $job->segments()->where('source', 'manual')->first();
        $this->assertNotNull($manual);
        $this->assertSame(18000, $manual->start_ms);
        $this->assertTrue($manual->approved);
    }

    public function test_show_lists_clips_and_index_exposes_clip_count(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $original = $this->makeOriginalVideo($user);

        SocialVideo::query()->create([
            'user_id' => $user->id,
            'parent_video_id' => $original->id,
            'kind' => VideoKind::Clip->value,
            'clip_index' => 0,
            'starts_at_ms' => 0,
            'ends_at_ms' => 15000,
            'title' => 'Clip 1',
            'video_path' => 'social-clips/1/1/clip-00.mp4',
        ]);

        $this->actingAs($user)
            ->get(route('videos.show', $original))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Videos/Show')
                ->where('video.is_clip', false)
                ->where('video.clips_count', 1)
                ->where('video.clips.0.title', 'Clip 1')
                ->has('video.facebook_content_type'));

        $this->actingAs($user)
            ->get(route('videos.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('videos.0.clips_count', 1)
                ->where('videos.0.is_clip', false));
    }
}
