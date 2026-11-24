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
    public function execute(Collection|array $videos, bool $withClips = false): int
    {
        $disk = Storage::disk('public');
        $analysisDisk = Storage::disk((string) config('video_processing.storage.analysis_disk', 'local'));
        $count = 0;
        $seen = [];
        $queue = collect($videos);

        foreach ($queue as $video) {
            if ($video->isOriginal() && $video->clips()->exists() && ! $withClips) {
                throw new InvalidArgumentException(
                    'No se puede eliminar el original mientras existan clips derivados. Elimina los clips primero o usa «Eliminar original y clips».',
                );
            }
        }

        $ordered = collect();
        foreach ($queue as $video) {
            if ($withClips && $video->isOriginal()) {
                foreach ($video->clips as $clip) {
                    $ordered->push($clip);
                }
            }
            $ordered->push($video);
        }

        foreach ($ordered as $video) {
            if (isset($seen[$video->id])) {
                continue;
            }
            $seen[$video->id] = true;

            if ($video->isOriginal()) {
                foreach ($video->processingJobs as $job) {
                    $analysisDisk->deleteDirectory('video-jobs/'.$job->id);
                }
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
