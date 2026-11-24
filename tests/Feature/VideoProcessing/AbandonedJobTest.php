<?php

namespace Tests\Feature\VideoProcessing;

use App\Models\User;
use App\Scheduling\Tasks\ReleaseAbandonedVideoJobsHandler;
use App\VideoProcessing\Enums\VideoProcessingJobStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesVideoProcessingFixtures;
use Tests\TestCase;

class AbandonedJobTest extends TestCase
{
    use MakesVideoProcessingFixtures;
    use RefreshDatabase;

    public function test_abandoned_claimed_job_is_released_back_to_queued(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $video = $this->makeOriginalVideo($user);
        $job = $this->queueSplitJob($user, $video);
        $worker = $this->makeWorker($user);

        $this->postJson('/worker/v1/jobs/claim', [], $this->workerHeaders())->assertOk();

        $job->refresh()->update([
            'heartbeat_at' => now()->subMinutes(10),
            'claimed_at' => now()->subMinutes(10),
        ]);
        $worker->update(['current_job_id' => $job->id, 'last_seen_at' => now()->subMinutes(10)]);

        $result = app(ReleaseAbandonedVideoJobsHandler::class)->run();

        $this->assertGreaterThanOrEqual(1, $result['processed']);
        $job->refresh();
        $this->assertSame(VideoProcessingJobStatus::Queued, $job->status);
        $this->assertNull($job->worker_id);
    }

    public function test_laravel_keeps_job_queued_when_no_worker_is_online(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $video = $this->makeOriginalVideo($user);
        $job = $this->queueSplitJob($user, $video);

        $this->assertSame(VideoProcessingJobStatus::Queued, $job->status);
        $this->assertDatabaseHas('video_processing_jobs', [
            'id' => $job->id,
            'status' => 'queued',
        ]);
    }
}
