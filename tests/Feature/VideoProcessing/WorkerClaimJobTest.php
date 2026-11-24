<?php

namespace Tests\Feature\VideoProcessing;

use App\Models\User;
use App\Models\VideoProcessingJob;
use App\VideoProcessing\Enums\VideoProcessingJobStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesVideoProcessingFixtures;
use Tests\TestCase;

class WorkerClaimJobTest extends TestCase
{
    use MakesVideoProcessingFixtures;
    use RefreshDatabase;

    public function test_worker_can_claim_queued_job(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $video = $this->makeOriginalVideo($user);
        $job = $this->queueSplitJob($user, $video);
        $this->makeWorker($user);

        $response = $this->postJson('/worker/v1/jobs/claim', [], $this->workerHeaders());

        $response->assertOk()
            ->assertJsonPath('job.id', $job->id)
            ->assertJsonPath('job.status', 'claimed')
            ->assertJsonPath('job.social_video_id', $video->id);

        $job->refresh();
        $this->assertSame(VideoProcessingJobStatus::Claimed, $job->status);
        $this->assertNotNull($job->worker_id);
        $this->assertNotNull($job->claimed_at);
    }

    public function test_second_worker_cannot_claim_the_same_job(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $video = $this->makeOriginalVideo($user);
        $this->queueSplitJob($user, $video);
        $this->makeWorker($user, 'token-one');
        $this->makeWorker($user, 'token-two');

        $this->postJson('/worker/v1/jobs/claim', [], $this->workerHeaders('token-one'))
            ->assertOk();

        $this->postJson('/worker/v1/jobs/claim', [], $this->workerHeaders('token-two'))
            ->assertNoContent();

        $this->assertSame(1, VideoProcessingJob::query()->where('status', 'claimed')->count());
    }

    public function test_unauthenticated_worker_cannot_claim(): void
    {
        $this->postJson('/worker/v1/jobs/claim')->assertUnauthorized();
    }
}
