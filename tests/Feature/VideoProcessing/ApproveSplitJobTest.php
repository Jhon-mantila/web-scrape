<?php

namespace Tests\Feature\VideoProcessing;

use App\Models\User;
use App\Models\VideoProcessingSegment;
use App\VideoProcessing\Enums\VideoProcessingJobStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesVideoProcessingFixtures;
use Tests\TestCase;

class ApproveSplitJobTest extends TestCase
{
    use MakesVideoProcessingFixtures;
    use RefreshDatabase;

    public function test_user_can_approve_segments_and_requeue_job_for_render(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $video = $this->makeOriginalVideo($user);
        $job = $this->queueSplitJob($user, $video);
        $job->update(['status' => VideoProcessingJobStatus::AwaitingApproval]);

        VideoProcessingSegment::query()->create([
            'job_id' => $job->id,
            'index' => 0,
            'start_ms' => 0,
            'end_ms' => 40000,
            'source' => 'smart',
            'approved' => false,
        ]);

        VideoProcessingSegment::query()->create([
            'job_id' => $job->id,
            'index' => 1,
            'start_ms' => 40000,
            'end_ms' => 90000,
            'source' => 'smart',
            'approved' => false,
        ]);

        $this->actingAs($user)->post(route('videos.split.approve', [$video, $job]), [
            'segments' => [
                ['id' => $job->segments()->where('index', 0)->value('id'), 'start_ms' => 0, 'end_ms' => 38000],
                ['id' => $job->segments()->where('index', 1)->value('id'), 'start_ms' => 38000, 'end_ms' => 90000],
            ],
        ])->assertRedirect();

        $job->refresh();
        $this->assertSame(VideoProcessingJobStatus::Queued, $job->status);
        $this->assertTrue($job->segments()->where('index', 0)->first()?->approved);
        $this->assertSame(38000, $job->segments()->where('index', 1)->first()?->start_ms);
    }
}
