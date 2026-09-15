<?php

namespace App\SocialPublishing\Actions;

use App\Models\WordpressPost;
use App\SocialPublishing\Enums\PublicationStatus;

class PublishSocialArticleBatchAction
{
    public function __construct(
        private readonly PublishSocialArticleAction $publish,
    ) {}

    /**
     * @param  list<array{platform: string, message?: ?string}>  $publications
     * @return array{published: int, failed: int, skipped: int, errors: list<string>}
     */
    public function execute(WordpressPost $post, array $publications, int $userId): array
    {
        $post->loadMissing('publications');

        $published = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];
        $processed = 0;

        foreach ($publications as $row) {
            $platform = (string) ($row['platform'] ?? '');

            if ($platform === '') {
                continue;
            }

            $site = $post->siteEnum();

            if ($site === null || ! $site->allowsPlatform($platform)) {
                $skipped++;

                continue;
            }

            $existing = $post->publications->firstWhere('platform', $platform);

            if ($existing !== null && $existing->blocksRepublish()) {
                $skipped++;

                continue;
            }

            if ($processed > 0) {
                sleep(2);
            }

            try {
                $result = $this->publish->execute(
                    $post->fresh(['publications']),
                    $platform,
                    $row['message'] ?? null,
                    $userId,
                );

                if (in_array($result->status, [PublicationStatus::Published, PublicationStatus::Scheduled], true)) {
                    $published++;
                } else {
                    $failed++;
                    $errors[] = ($result->platformLabel()).': '.($result->last_error ?? 'Error');
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = $platform.': '.$e->getMessage();
            }

            $processed++;
            $post->load('publications');
        }

        return compact('published', 'failed', 'skipped', 'errors');
    }
}
