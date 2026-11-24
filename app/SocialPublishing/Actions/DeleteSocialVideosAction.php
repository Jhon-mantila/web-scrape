<?php

namespace App\SocialPublishing\Actions;

use App\Models\SocialVideo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

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
            if ($video->isOriginal() && $video->clips()->exists()) {
                throw new InvalidArgumentException(
                    'No se puede eliminar el original mientras existan clips derivados. Elimina los clips primero.',
                );
            }

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
