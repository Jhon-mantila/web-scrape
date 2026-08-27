<?php

namespace App\SocialPublishing\Actions;

use App\Models\SocialVideo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class DeleteSocialVideosAction
{
    /**
     * @param  Collection<int, SocialVideo>|list<SocialVideo>  $videos
     */
    public function execute(Collection|array $videos): int
    {
        $disk = Storage::disk('public');
        $count = 0;

        foreach ($videos as $video) {
            $disk->delete(array_filter([
                $video->video_path,
                $video->thumbnail_path,
            ]));

            $video->delete();
            $count++;
        }

        return $count;
    }
}
