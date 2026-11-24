<?php

namespace Tests\Feature\VideoProcessing;

use App\Models\User;
use App\Models\VideoProcessingSegment;
use App\VideoProcessing\Enums\VideoProcessingJobStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesVideoProcessingFixtures;
use Tests\TestCase;

class SubmitAnalysisTest extends TestCase
{
    use MakesVideoProcessingFixtures;
    use RefreshDatabase;

    public function test_worker_submits_analysis_and_job_awaits_approval(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $video = $this->makeOriginalVideo($user);
        $job = $this->queueSplitJob($user, $video);
        $this->makeWorker($user);

        $this->postJson('/worker/v1/jobs/claim', [], $this->workerHeaders())->assertOk();

        $payload = [
            'duration_ms' => 380000,
            'width' => 1920,
            'height' => 1080,
            'fps' => 30,
            'target_clip_count' => 7,
            'recommended_clip_count' => 6,
            'recommendation_reason' => 'Forzar 7 cortaría una explicación a la mitad.',
            'segments' => [
                [
                    'index' => 0,
                    'start_ms' => 0,
                    'end_ms' => 52000,
                    'source' => 'smart',
                    'score' => 0.91,
                    'warnings' => [],
                ],
                [
                    'index' => 1,
                    'start_ms' => 52000,
                    'end_ms' => 110000,
                    'source' => 'smart',
                    'score' => 0.84,
                    'warnings' => ['escena_larga'],
                ],
            ],
        ];

        $this->putJson('/worker/v1/jobs/'.$job->id.'/analysis', $payload, $this->workerHeaders())
            ->assertOk();

        $job->refresh();
        $this->assertSame(VideoProcessingJobStatus::AwaitingApproval, $job->status);
        $this->assertSame(6, $job->result_summary['recommended_clip_count']);
        $this->assertNotNull($job->analysis_path);
        $this->assertSame(2, VideoProcessingSegment::query()->where('job_id', $job->id)->count());
        $this->assertSame(0, $job->segments()->first()->start_ms);
        $this->assertSame(52000, $job->segments()->orderBy('index')->get()[1]->start_ms);
    }
}
