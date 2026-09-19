<?php

namespace App\SocialPublishing\Actions;

use App\Models\SocialVideo;
use App\ProcessScraping\Support\BackgroundRunQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteSocialPublishRunAction
{
    public function __construct(
        private readonly PublishAllSocialPublicationsAction $publishAll,
    ) {}

    /**
     * @param  list<int>|null  $publicationIds
     */
    public function execute(int $userId, string $runId, int $videoId, ?array $publicationIds): void
    {
        try {
            $video = SocialVideo::query()->with('publications')->findOrFail($videoId);

            $summary = $this->publishAll->execute($video, $publicationIds, $userId);

            $selected = $publicationIds !== null ? count($publicationIds) : $video->publications->count();
            $message = "Envío ({$selected} plataforma(s)): {$summary['published']} OK, {$summary['failed']} fallidas, {$summary['skipped']} omitidas.";

            BackgroundRunQueue::completeRun(
                $userId,
                $runId,
                $summary,
                $message,
                ($summary['failed'] ?? 0) > 0,
            );
        } catch (Throwable $e) {
            Log::error('Publicación social en segundo plano falló', [
                'user_id' => $userId,
                'run_id' => $runId,
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            BackgroundRunQueue::failRun($userId, $runId, 'Error al publicar: '.$e->getMessage());
        }
    }
}
