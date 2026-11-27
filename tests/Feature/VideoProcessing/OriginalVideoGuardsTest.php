<?php

namespace Tests\Feature\VideoProcessing;

use App\Models\SocialVideo;
use App\Models\User;
use App\VideoProcessing\Enums\VideoKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    public function test_can_delete_original_and_clips_together_when_requested(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $original = $this->makeOriginalVideo($user);
        $clipPath = 'social-clips/1/1/clip-00.mp4';
        Storage::disk('public')->put($clipPath, 'clip-bytes');

        $clip = SocialVideo::query()->create([
            'user_id' => $user->id,
            'parent_video_id' => $original->id,
            'kind' => VideoKind::Clip->value,
            'clip_index' => 0,
            'title' => 'Clip 1',
            'video_path' => $clipPath,
        ]);

        $this->actingAs($user)
            ->delete(route('videos.destroy', $original), ['with_clips' => true])
            ->assertRedirect(route('videos.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('social_videos', ['id' => $original->id]);
        $this->assertDatabaseMissing('social_videos', ['id' => $clip->id]);
        $this->assertFalse(Storage::disk('public')->exists($original->video_path));
        $this->assertFalse(Storage::disk('public')->exists($clipPath));
        $this->assertFalse(Storage::disk('public')->directoryExists('social-clips/1/1'));
        $this->assertFalse(Storage::disk('public')->directoryExists('social-clips/1'));
    }

    public function test_deleting_clip_prunes_empty_job_folder_but_keeps_sibling_clips(): void
    {
        $this->fakeVideoDisks();

        $user = User::factory()->create();
        $original = $this->makeOriginalVideo($user);
        $keepPath = 'social-clips/'.$original->id.'/1/clip-00.mp4';
        $removePath = 'social-clips/'.$original->id.'/2/clip-00.mp4';
        Storage::disk('public')->put($keepPath, 'keep');
        Storage::disk('public')->put($removePath, 'remove');

        SocialVideo::query()->create([
            'user_id' => $user->id,
            'parent_video_id' => $original->id,
            'kind' => VideoKind::Clip->value,
            'clip_index' => 0,
            'title' => 'Clip keep',
            'video_path' => $keepPath,
        ]);

        $clip = SocialVideo::query()->create([
            'user_id' => $user->id,
            'parent_video_id' => $original->id,
            'kind' => VideoKind::Clip->value,
            'clip_index' => 1,
            'title' => 'Clip remove',
            'video_path' => $removePath,
        ]);

        $this->actingAs($user)
            ->delete(route('videos.destroy', $clip))
            ->assertRedirect(route('videos.index'))
            ->assertSessionHas('success');

        $this->assertFalse(Storage::disk('public')->exists($removePath));
        $this->assertFalse(Storage::disk('public')->directoryExists('social-clips/'.$original->id.'/2'));
        $this->assertTrue(Storage::disk('public')->exists($keepPath));
        $this->assertTrue(Storage::disk('public')->directoryExists('social-clips/'.$original->id.'/1'));
        $this->assertTrue(Storage::disk('public')->directoryExists('social-clips/'.$original->id));
    }
}
