<?php

namespace App\ProcessScraping\Actions;

use App\ProcessScraping\Support\BackgroundRunQueue;
use App\ProcessScraping\Support\PipelineSummaryFormatter;
use App\SendWordpress\Actions\SendPostToWordpressAction;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecutePipelineRunAction
{
    public function __construct(
        private readonly RunNewsPipelineAction $pipeline,
        private readonly SendPostToWordpressAction $sendWordpress,
        private readonly PipelineSummaryFormatter $summaryFormatter,
    ) {}

    public function execute(int $userId, string $runId): void
    {
        $run = BackgroundRunQueue::find($userId, $runId);

        if ($run === null) {
            return;
        }

        $options = $run['options'] ?? ($run['job']['options'] ?? []);
        $limit = max(1, (int) ($options['limit'] ?? 5));
        $mode = (string) ($options['mode'] ?? 'draft');

        try {
            if ($options['wordpress_only'] ?? false) {
                BackgroundRunQueue::beginStep($userId, $runId, 'wordpress', 'Enviando artículos a WordPress…');

                $summary = [
                    'wordpress' => $this->sendWordpress->execute($limit, $mode),
                ];

                $message = $this->summaryFormatter->formatWordpress($summary['wordpress'], $mode);
                $failed = ($summary['wordpress']['failed'] ?? 0) > 0;

                BackgroundRunQueue::finishStep($userId, $runId, 'wordpress', $message);
                BackgroundRunQueue::completeRun($userId, $runId, $summary, $message, $failed);

                return;
            }

            $summary = $this->pipeline->execute(
                $limit,
                $mode,
                (bool) ($options['force'] ?? false),
                (bool) ($options['include_raw_html'] ?? false),
                (bool) ($options['skip_scrape'] ?? false),
                (bool) ($options['skip_research'] ?? false),
                (bool) ($options['skip_generate'] ?? false),
                ! ($options['send_wordpress'] ?? false),
                $userId,
            );

            $message = $this->summaryFormatter->formatPipeline($summary, $options);

            $hasFailures = ($summary['ai']['failed'] ?? 0) > 0
                || (($options['send_wordpress'] ?? false) && ($summary['wordpress']['failed'] ?? 0) > 0);

            BackgroundRunQueue::completeRun($userId, $runId, $summary, $message, $hasFailures);
        } catch (Throwable $e) {
            Log::error('Pipeline en segundo plano falló', [
                'user_id' => $userId,
                'run_id' => $runId,
                'message' => $e->getMessage(),
            ]);

            BackgroundRunQueue::failRun($userId, $runId, 'Error: '.$e->getMessage());
        }
    }
}
