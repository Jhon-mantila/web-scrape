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

        $clipRoot = trim((string) config('video_processing.storage.clips_directory', 'social-clips'), '/');
        $dirsToPrune = [];

        foreach ($ordered as $video) {
            if (isset($seen[$video->id])) {
                continue;
            }
            $seen[$video->id] = true;

            if ($video->isOriginal()) {
                foreach ($video->processingJobs as $job) {
                    $analysisDisk->deleteDirectory('video-jobs/'.$job->id);
                }

                $dirsToPrune[] = $clipRoot.'/'.$video->id;
            }

            foreach (array_filter([$video->video_path, $video->thumbnail_path]) as $path) {
                $dir = dirname(str_replace('\\', '/', (string) $path));

                if ($dir !== '.' && $dir !== '/' && str_starts_with($dir, $clipRoot.'/')) {
                    $dirsToPrune[] = $dir;
                }
            }

            $disk->delete(array_filter([
                $video->video_path,
                $video->thumbnail_path,
            ]));

            $video->delete();
            $count++;
        }

        $this->pruneEmptyClipDirectories($disk, $dirsToPrune, $clipRoot);

        return $count;
    }

    /**
     * @param  list<string>  $directories
     */
    private function pruneEmptyClipDirectories($disk, array $directories, string $clipRoot): void
    {
        $clipRoot = trim($clipRoot, '/');
        $unique = array_values(array_unique(array_filter($directories)));
        usort($unique, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($unique as $directory) {
            $this->deleteEmptyDirectoryChain($disk, $directory, $clipRoot);
        }
    }

    private function deleteEmptyDirectoryChain($disk, string $directory, string $stopAt): void
    {
        $directory = trim(str_replace('\\', '/', $directory), '/');
        $stopAt = trim($stopAt, '/');

        while ($directory !== '' && $directory !== '.' && $directory !== $stopAt) {
            if (! $this->directoryIsEmpty($disk, $directory)) {
                break;
            }

            $disk->deleteDirectory($directory);

            $parent = str_replace('\\', '/', dirname($directory));

            if ($parent === $directory || $parent === '.') {
                break;
            }

            $directory = $parent;
        }
    }

    private function directoryIsEmpty($disk, string $directory): bool
    {
        try {
            return $disk->files($directory) === [] && $disk->directories($directory) === [];
        } catch (\Throwable) {
            return false;
        }
    }
}
