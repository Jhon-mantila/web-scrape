<?php

namespace Tests\Feature\VideoProcessing;

use App\Models\SocialPublication;
use App\Models\SocialVideo;
use App\Models\User;
use App\Models\VideoProcessingSegment;
use App\SocialPublishing\Actions\PublishSocialPublicationAction;
use App\SocialPublishing\Contracts\SocialPublisherInterface;
use App\SocialPublishing\DTO\PublishResult;
use App\SocialPublishing\Enums\PublicationStatus;
use App\SocialPublishing\Registry\SocialPublisherRegistry;
use App\SocialPublishing\Support\SocialPublishRunPlanner;
use App\VideoProcessing\Enums\VideoProcessingJobStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\MakesVideoProcessingFixtures;
use Tests\TestCase;

class PublishClipTest extends TestCase
{
    use MakesVideoProcessingFixtures;
    use RefreshDatabase;

    public function test_clip_show_uses_the_same_video_page_with_own_drafts(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $clip = $this->makeClipWithDrafts($user);

        $this->actingAs($user)
            ->get(route('videos.show', $clip))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Videos/Show')
                ->where('video.is_clip', true)
                ->where('video.parent.id', $clip->parent_video_id)
                ->where('video.publications.0.platform', 'youtube')
                ->where('video.publications.0.status', 'draft')
                ->where('video.publications.0.caption_edited', 'Caption del original'));
    }

    public function test_publish_planner_only_includes_the_clip_publications(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $clip = $this->makeClipWithDrafts($user);
        $parent = $clip->parent()->with('publications')->firstOrFail();

        $steps = app(SocialPublishRunPlanner::class)->stepsForVideo($clip, null);

        $this->assertCount(1, $steps);
        $this->assertSame($clip->publications()->first()->id, $steps[0]['publication_id']);
        $this->assertNotSame($parent->publications()->first()->id, $steps[0]['publication_id']);
    }

    public function test_publishing_a_clip_does_not_change_the_original_publication(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $clip = $this->makeClipWithDrafts($user);
        $parent = $clip->parent()->with('publications')->firstOrFail();
        $clipPublication = $clip->publications()->firstOrFail();
        $parentPublication = $parent->publications()->firstOrFail();

        app(SocialPublisherRegistry::class)->register(new class implements SocialPublisherInterface
        {
            public function platform(): string
            {
                return 'youtube';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function publish(SocialPublication $publication): PublishResult
            {
                return PublishResult::ok('yt-clip-1', 'https://youtu.be/clip');
            }
        });

        $result = app(PublishSocialPublicationAction::class)->execute($clipPublication);

        $this->assertSame(PublicationStatus::Published, $result->status);
        $this->assertSame('yt-clip-1', $result->external_id);
        $this->assertSame(PublicationStatus::Draft, $parentPublication->fresh()->status);
        $this->assertNull($parentPublication->fresh()->external_id);
        $this->assertSame($parent->video_path, $parent->fresh()->video_path);
    }

    public function test_materialized_clip_copies_parent_captions_as_draft(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $clip = $this->makeClipWithDrafts($user);

        $this->assertSame('Caption del original', $clip->publications()->first()?->caption_edited);
        $this->assertSame(PublicationStatus::Draft, $clip->publications()->first()?->status);
    }

    private function makeClipWithDrafts(User $user): SocialVideo
    {
        $video = $this->makeOriginalVideo($user);
        $video->publications()->first()?->update([
            'caption_edited' => 'Caption del original',
            'caption_generated' => 'Caption generado',
        ]);

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

        $this->post('/worker/v1/jobs/'.$job->id.'/clips/0', [
            'clip' => UploadedFile::fake()->create('clip-00.mp4', 200, 'video/mp4'),
        ], $this->workerHeaders())->assertCreated();

        $clip = SocialVideo::query()->where('parent_video_id', $video->id)->firstOrFail();

        return $clip->fresh(['publications', 'parent.publications']);
    }
}
