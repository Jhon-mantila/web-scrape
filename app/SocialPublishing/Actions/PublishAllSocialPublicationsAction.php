<?php

namespace App\SocialPublishing\Actions;

use App\Models\SocialVideo;
use App\ProcessScraping\Support\PipelineRunState;
use App\SocialPublishing\Enums\PublicationStatus;

class PublishAllSocialPublicationsAction
{
    public function __construct(
        private readonly PublishSocialPublicationAction $publish,
    ) {}

    /**
     * @param  list<int>|null  $publicationIds  Si se indica, solo se procesan esas publicaciones.
     * @return array{published: int, failed: int, skipped: int}
     */
    public function execute(SocialVideo $video, ?array $publicationIds = null, ?int $progressUserId = null): array
    {
        $video->load('publications');

        $published = 0;
        $failed = 0;
        $skipped = 0;
        $processed = 0;

        foreach ($video->publications as $publication) {
            if ($publicationIds !== null && ! in_array($publication->id, $publicationIds, true)) {
                continue;
            }

            $stepKey = 'pub_'.$publication->id;

            if (config("social.platforms.{$publication->platform}.coming_soon")) {
                $skipped++;
                $this->progressSkip($progressUserId, $stepKey, 'Próximamente');

                continue;
            }

            if ($publication->status === PublicationStatus::Published || $publication->status === PublicationStatus::Scheduled) {
                $skipped++;
                $this->progressSkip($progressUserId, $stepKey, 'Ya publicado');

                continue;
            }

            if ($processed > 0) {
                // Tras subidas pesadas (YouTube/Facebook), el DNS del contenedor puede fallar momentáneamente.
                sleep(3);
            }

            $label = config("social.platforms.{$publication->platform}.label") ?: $publication->platform;
            $this->progressBegin($progressUserId, $stepKey, "Publicando en {$label}…");

            $result = $this->publish->execute($publication);

            if ($result->status === PublicationStatus::Published || $result->status === PublicationStatus::Scheduled) {
                $published++;
                $this->progressFinish($progressUserId, $stepKey, 'OK');
            } else {
                $failed++;
                $detail = $result->last_error ? mb_substr($result->last_error, 0, 120) : 'Error';
                $this->progressFinish($progressUserId, $stepKey, $detail);
            }

            $processed++;
        }

        return compact('published', 'failed', 'skipped');
    }

    private function progressBegin(?int $userId, string $stepKey, string $message): void
    {
        if ($userId === null) {
            return;
        }

        PipelineRunState::beginStep($userId, $stepKey, $message);
    }

    private function progressFinish(?int $userId, string $stepKey, string $detail): void
    {
        if ($userId === null) {
            return;
        }

        PipelineRunState::finishStep($userId, $stepKey, $detail);
    }

    private function progressSkip(?int $userId, string $stepKey, string $detail): void
    {
        if ($userId === null) {
            return;
        }

        PipelineRunState::skipStep($userId, $stepKey, $detail);
    }
}
