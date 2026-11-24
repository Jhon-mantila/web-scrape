<?php

namespace Tests\Feature\VideoProcessing;

use App\Models\SocialVideo;
use App\Models\User;
use App\Models\VideoProcessingSegment;
use App\SocialPublishing\Enums\PublicationStatus;
use App\VideoProcessing\Enums\VideoKind;
use App\VideoProcessing\Enums\VideoProcessingJobStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesVideoProcessingFixtures;
use Tests\TestCase;

class MaterializeClipTest extends TestCase
{
    use MakesVideoProcessingFixtures;
    use RefreshDatabase;

    public function test_uploading_clip_creates_child_social_video_with_draft_publications(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $video = $this->makeOriginalVideo($user);
        $job = $this->queueSplitJob($user, $video);
        $this->makeWorker($user);

        $this->postJson('/worker/v1/jobs/claim', [], $this->workerHeaders())->assertOk();
        $job->update(['status' => VideoProcessingJobStatus::Uploading]);

        VideoProcessingSegment::query()->create([
            'job_id' => $job->id,
            'index' => 0,
            'start_ms' => 0,
            'end_ms' => 45000,
            'source' => 'smart',
            'approved' => true,
        ]);

        $file = UploadedFile::fake()->create('clip-00.mp4', 200, 'video/mp4');

        $this->post('/worker/v1/jobs/'.$job->id.'/clips/0', [
            'clip' => $file,
        ], $this->workerHeaders())->assertCreated();

        $clip = SocialVideo::query()->where('parent_video_id', $video->id)->first();
        $this->assertNotNull($clip);
        $this->assertSame(VideoKind::Clip->value, $clip->kind);
        $this->assertSame($video->id, $clip->parent_video_id);
        $this->assertSame(0, $clip->clip_index);
        $this->assertSame($video->video_path, $video->fresh()->video_path);
        $this->assertNotSame($video->video_path, $clip->video_path);
        $this->assertTrue(Storage::disk('public')->exists($clip->video_path));
        $this->assertSame('youtube', $clip->publications()->first()->platform);
        $this->assertSame(PublicationStatus::Draft, $clip->publications()->first()->status);
    }

    public function test_uploading_the_same_clip_index_twice_does_not_duplicate_social_video(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $video = $this->makeOriginalVideo($user);
        $job = $this->queueSplitJob($user, $video);
        $this->makeWorker($user);

        $this->postJson('/worker/v1/jobs/claim', [], $this->workerHeaders())->assertOk();
        $job->update(['status' => VideoProcessingJobStatus::Uploading]);

        VideoProcessingSegment::query()->create([
            'job_id' => $job->id,
            'index' => 0,
            'start_ms' => 0,
            'end_ms' => 45000,
            'source' => 'smart',
            'approved' => true,
        ]);

        $headers = $this->workerHeaders();

        $this->post('/worker/v1/jobs/'.$job->id.'/clips/0', [
            'clip' => UploadedFile::fake()->create('clip-00.mp4', 200, 'video/mp4'),
        ], $headers)->assertCreated();

        $this->post('/worker/v1/jobs/'.$job->id.'/clips/0', [
            'clip' => UploadedFile::fake()->create('clip-00-retry.mp4', 200, 'video/mp4'),
        ], $headers)->assertOk();

        $this->assertSame(1, SocialVideo::query()->where('parent_video_id', $video->id)->count());
    }

    public function test_a_second_job_replaces_the_clip_slot_instead_of_failing_unique(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $video = $this->makeOriginalVideo($user);
        $this->makeWorker($user);

        $first = $this->queueSplitJob($user, $video);
        $this->postJson('/worker/v1/jobs/claim', [], $this->workerHeaders())->assertOk();
        $first->update(['status' => VideoProcessingJobStatus::Uploading]);
        VideoProcessingSegment::query()->create([
            'job_id' => $first->id,
            'index' => 0,
            'start_ms' => 0,
            'end_ms' => 20000,
            'source' => 'smart',
            'approved' => true,
        ]);
        $this->post('/worker/v1/jobs/'.$first->id.'/clips/0', [
            'clip' => UploadedFile::fake()->create('clip-a.mp4', 200, 'video/mp4'),
        ], $this->workerHeaders())->assertCreated();

        $first->update([
            'status' => VideoProcessingJobStatus::Completed,
            'worker_id' => null,
        ]);

        $second = $this->queueSplitJob($user, $video, ['framing' => 'blurred_background']);
        $this->postJson('/worker/v1/jobs/claim', [], $this->workerHeaders())->assertOk();
        $second->update(['status' => VideoProcessingJobStatus::Uploading]);
        VideoProcessingSegment::query()->create([
            'job_id' => $second->id,
            'index' => 0,
            'start_ms' => 1000,
            'end_ms' => 25000,
            'source' => 'smart',
            'approved' => true,
        ]);

        $this->post('/worker/v1/jobs/'.$second->id.'/clips/0', [
            'clip' => UploadedFile::fake()->create('clip-b.mp4', 200, 'video/mp4'),
        ], $this->workerHeaders())->assertOk();

        $clips = SocialVideo::query()->where('parent_video_id', $video->id)->get();
        $this->assertCount(1, $clips);
        $this->assertSame($second->id, $clips[0]->source_job_id);
        $this->assertSame(1000, $clips[0]->starts_at_ms);
        $this->assertStringContainsString((string) $second->id, (string) $clips[0]->video_path);
    }
}
