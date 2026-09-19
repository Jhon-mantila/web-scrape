<?php

namespace App\ProcessScraping\Actions;

use App\ProcessScraping\Support\BackgroundRunQueue;
use App\SocialPublishing\Actions\ExecuteSocialPublishRunAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessBackgroundRunQueueAction
{
    public function __construct(
        private readonly ExecutePipelineRunAction $pipelineRun,
        private readonly ExecuteSocialPublishRunAction $socialPublishRun,
    ) {}

    public function execute(int $userId): void
    {
        $lock = Cache::lock("background_run_worker:user:{$userId}", 7200);

        if (! $lock->get()) {
            return;
        }

        try {
            while ($run = BackgroundRunQueue::claimForExecution($userId)) {
                $runId = (string) ($run['id'] ?? '');

                if ($runId === '') {
                    break;
                }

                try {
                    match ($run['kind'] ?? '') {
                        'social_publish' => $this->socialPublishRun->execute(
                            $userId,
                            $runId,
                            (int) ($run['video_id'] ?? 0),
                            $run['publication_ids'] ?? null,
                        ),
                        default => $this->pipelineRun->execute($userId, $runId),
                    };
                } catch (Throwable $e) {
                    Log::error('Background run falló', [
                        'user_id' => $userId,
                        'run_id' => $runId,
                        'error' => $e->getMessage(),
                    ]);

                    BackgroundRunQueue::failRun($userId, $runId, 'Error: '.$e->getMessage());
                }
            }
        } finally {
            $lock->release();
        }
    }
}
