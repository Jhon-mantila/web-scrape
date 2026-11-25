<?php

namespace Tests\Feature\VideoProcessing;

use App\Models\SocialVideo;
use App\Models\User;
use App\Models\VideoProcessingJob;
use App\SocialPublishing\Enums\PublicationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MakesVideoProcessingFixtures;
use Tests\TestCase;

class QueueAnalysisJobTest extends TestCase
{
    use MakesVideoProcessingFixtures;
    use RefreshDatabase;

    public function test_user_can_queue_analysis_job_without_reuploading_original(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $path = 'social-videos/original.mp4';
        Storage::disk('public')->put($path, 'original-bytes');

        $video = SocialVideo::query()->create([
            'user_id' => $user->id,
            'title' => 'Video largo',
            'video_path' => $path,
            'thumbnail_path' => null,
            'notes' => 'notas',
        ]);

        $response = $this->actingAs($user)->post(route('videos.split.store', $video), [
            'target_clip_count' => 7,
            'min_duration_seconds' => 15,
            'max_duration_seconds' => 60,
            'mode' => 'smart',
            'framing' => 'smart_crop',
        ]);

        $response->assertRedirect();

        $job = VideoProcessingJob::query()->first();
        $this->assertNotNull($job);
        $this->assertSame($video->id, $job->social_video_id);
        $this->assertSame($user->id, $job->user_id);
        $this->assertSame('queued', $job->status->value);
        $this->assertSame(7, $job->params['target_clip_count']);
        $this->assertSame('smart', $job->params['mode']);
        $this->assertSame('smart_crop', $job->params['framing']);
        $this->assertNull($job->params['fit_fill']);
        $this->assertTrue($job->params['inherit_platforms']);
        $this->assertSame([], $job->params['clip_platforms']);

        $video->refresh();
        $this->assertSame($path, $video->video_path);
        $this->assertSame('original-bytes', Storage::disk('public')->get($path));
        $this->assertCount(1, Storage::disk('public')->files('social-videos'));
    }

    public function test_fit_framing_stores_optional_template_for_the_worker(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $user = User::factory()->create();
        $path = 'social-videos/original.mp4';
        Storage::disk('public')->put($path, 'original-bytes');

        $video = SocialVideo::query()->create([
            'user_id' => $user->id,
            'title' => 'Video largo',
            'video_path' => $path,
        ]);

        $this->actingAs($user)->post(route('videos.split.store', $video), [
            'target_clip_count' => 3,
            'min_duration_seconds' => 15,
            'max_duration_seconds' => 60,
            'mode' => 'smart',
            'framing' => 'fit',
            'fit_fill' => 'white',
            'fit_template' => UploadedFile::fake()->image('cover.png', 1080, 1920),
        ])->assertRedirect();

        $job = VideoProcessingJob::query()->first();
        $this->assertNotNull($job);
        $this->assertSame('fit', $job->params['framing']);
        $this->assertSame('white', $job->params['fit_fill']);
        $this->assertNotEmpty($job->params['fit_template_path'] ?? null);
        $this->assertTrue(Storage::disk('local')->exists($job->params['fit_template_path']));

        $this->makeWorker($user);
        $this->postJson('/worker/v1/jobs/claim', [], $this->workerHeaders())->assertOk();
        $this->get('/worker/v1/jobs/'.$job->id.'/template', $this->workerHeaders())->assertOk();
    }

    public function test_fit_framing_defaults_to_blur_fill(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $path = 'social-videos/original.mp4';
        Storage::disk('public')->put($path, 'original-bytes');

        $video = SocialVideo::query()->create([
            'user_id' => $user->id,
            'title' => 'Video largo',
            'video_path' => $path,
        ]);

        $this->actingAs($user)->post(route('videos.split.store', $video), [
            'target_clip_count' => 3,
            'min_duration_seconds' => 15,
            'max_duration_seconds' => 60,
            'mode' => 'smart',
            'framing' => 'fit',
        ])->assertRedirect();

        $job = VideoProcessingJob::query()->first();
        $this->assertNotNull($job);
        $this->assertSame('blur', $job->params['fit_fill']);
        $this->assertTrue($job->params['fit_part_label']);
        $this->assertSame('#FFFFFF', $job->params['fit_part_label_color']);
    }

    public function test_fit_framing_stores_part_label_color(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $path = 'social-videos/original.mp4';
        Storage::disk('public')->put($path, 'original-bytes');

        $video = SocialVideo::query()->create([
            'user_id' => $user->id,
            'title' => 'Video largo',
            'video_path' => $path,
        ]);

        $this->actingAs($user)->post(route('videos.split.store', $video), [
            'target_clip_count' => 3,
            'min_duration_seconds' => 15,
            'max_duration_seconds' => 60,
            'mode' => 'smart',
            'framing' => 'fit',
            'fit_part_label' => true,
            'fit_part_label_color' => '#ffcc00',
        ])->assertRedirect();

        $job = VideoProcessingJob::query()->first();
        $this->assertNotNull($job);
        $this->assertTrue($job->params['fit_part_label']);
        $this->assertSame('#FFCC00', $job->params['fit_part_label_color']);
    }

    public function test_split_job_stores_selected_clip_platforms(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $path = 'social-videos/original.mp4';
        Storage::disk('public')->put($path, 'original-bytes');

        $video = SocialVideo::query()->create([
            'user_id' => $user->id,
            'title' => 'Video largo',
            'video_path' => $path,
        ]);
        $video->publications()->create(['platform' => 'youtube', 'status' => PublicationStatus::Draft]);
        $video->publications()->create(['platform' => 'facebook_esquinaweb', 'status' => PublicationStatus::Draft]);

        $this->actingAs($user)->post(route('videos.split.store', $video), [
            'target_clip_count' => 3,
            'min_duration_seconds' => 15,
            'max_duration_seconds' => 60,
            'mode' => 'smart',
            'framing' => 'smart_crop',
            'inherit_platforms' => true,
            'clip_platforms' => ['youtube'],
            'clip_platforms_set' => 1,
        ])->assertRedirect();

        $job = VideoProcessingJob::query()->first();
        $this->assertNotNull($job);
        $this->assertTrue($job->params['inherit_platforms']);
        $this->assertSame(['youtube'], $job->params['clip_platforms']);
    }

    public function test_fit_part_label_can_be_disabled(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $path = 'social-videos/original.mp4';
        Storage::disk('public')->put($path, 'original-bytes');

        $video = SocialVideo::query()->create([
            'user_id' => $user->id,
            'title' => 'Video largo',
            'video_path' => $path,
        ]);

        $this->actingAs($user)->post(route('videos.split.store', $video), [
            'target_clip_count' => 3,
            'min_duration_seconds' => 15,
            'max_duration_seconds' => 60,
            'mode' => 'smart',
            'framing' => 'fit',
            'fit_part_label' => 0,
        ])->assertRedirect();

        $job = VideoProcessingJob::query()->first();
        $this->assertNotNull($job);
        $this->assertFalse((bool) $job->params['fit_part_label']);
    }
}
