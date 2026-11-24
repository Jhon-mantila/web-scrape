<?php

namespace Tests\Feature\VideoProcessing;

use App\Models\SocialVideo;
use App\Models\User;
use App\VideoProcessing\Enums\VideoKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesVideoProcessingFixtures;
use Tests\TestCase;

class OriginalVideoGuardsTest extends TestCase
{
    use MakesVideoProcessingFixtures;
    use RefreshDatabase;

    public function test_index_lists_originals_not_clips(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $original = $this->makeOriginalVideo($user);

        SocialVideo::query()->create([
            'user_id' => $user->id,
            'parent_video_id' => $original->id,
            'kind' => VideoKind::Clip->value,
            'clip_index' => 0,
            'title' => 'Clip 1',
            'video_path' => 'social-clips/1/1/clip-00.mp4',
        ]);

        $response = $this->actingAs($user)->get(route('videos.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Videos/Index')
            ->has('videos', 1)
            ->where('videos.0.id', $original->id)
            ->where('videos.0.title', 'Video largo'));
    }

    public function test_cannot_delete_original_while_clips_exist(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $original = $this->makeOriginalVideo($user);

        SocialVideo::query()->create([
            'user_id' => $user->id,
            'parent_video_id' => $original->id,
            'kind' => VideoKind::Clip->value,
            'clip_index' => 0,
            'title' => 'Clip 1',
            'video_path' => 'social-clips/1/1/clip-00.mp4',
        ]);

        $this->actingAs($user)
            ->delete(route('videos.destroy', $original))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('social_videos', ['id' => $original->id]);
    }
}
