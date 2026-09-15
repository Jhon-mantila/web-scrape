<?php

namespace App\Wordpress\Actions;

use App\Models\WordpressPost;
use App\Models\WordpressSyncState;
use App\SocialPublishing\Enums\WordpressSite;
use App\Wordpress\WordpressPostReader;
use Illuminate\Support\Carbon;

class SyncWordpressPostsAction
{
    public function __construct(
        private readonly WordpressPostReader $reader,
    ) {}

    /**
     * @param  list<string>|null  $sites
     * @return array{
     *     sites: array<string, array{
     *         added: int,
     *         skipped: int,
     *         new_fetched: int,
     *         backfill_fetched: int,
     *         error: ?string
     *     }>,
     *     total_added: int
     * }
     */
    public function execute(
        ?array $sites = null,
        ?int $perPage = null,
        ?int $backfillPages = null,
        ?int $newPages = null,
    ): array {
        $perPage = $this->clamp(
            $perPage ?? (int) config('wordpress_sources.sync.per_page', 20),
            'per_page',
        );
        $backfillPages = $this->clamp(
            $backfillPages ?? (int) config('wordpress_sources.sync.backfill_pages', 5),
            'backfill_pages',
        );
        $newPages = $this->clamp(
            $newPages ?? (int) config('wordpress_sources.sync.new_pages', 3),
            'new_pages',
        );

        $targets = $this->resolveSites($sites);
        $summary = ['sites' => [], 'total_added' => 0];

        foreach ($targets as $site) {
            try {
                $result = $this->syncSite($site, $perPage, $backfillPages, $newPages);
                $summary['sites'][$site->value] = $result;
                $summary['total_added'] += $result['added'];
            } catch (\Throwable $e) {
                $summary['sites'][$site->value] = [
                    'added' => 0,
                    'skipped' => 0,
                    'new_fetched' => 0,
                    'backfill_fetched' => 0,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $summary;
    }

    private function syncSite(
        WordpressSite $site,
        int $perPage,
        int $backfillPages,
        int $newPages,
    ): array {
        $state = $this->resolveSyncState($site, $perPage);
        $existingIds = WordpressPost::query()
            ->forSite($site->value)
            ->pluck('wp_post_id')
            ->flip();

        $added = 0;
        $skipped = 0;
        $newFetched = 0;
        $backfillFetched = 0;

        if ($state->newest_wp_published_at !== null) {
            $newRows = $this->reader->fetchNewPostsSince(
                $site,
                $state->newest_wp_published_at,
                $newPages,
                $perPage,
            );
            $newFetched = count($newRows);
            $import = $this->importRows($site, $newRows, $existingIds);
            $added += $import['added'];
            $skipped += $import['skipped'];
        }

        if (! $state->backfill_complete) {
            $backfill = $this->reader->fetchBackfillBatch(
                $site,
                $state->backfill_next_page,
                $backfillPages,
                $perPage,
            );

            $backfillFetched = count($backfill['posts']);
            $import = $this->importRows($site, $backfill['posts'], $existingIds);
            $added += $import['added'];
            $skipped += $import['skipped'];

            $nextPage = $state->backfill_next_page + $backfill['pages_fetched'];
            $state->backfill_next_page = max(1, $nextPage);
            $state->backfill_complete = $backfill['archive_exhausted'] || $backfill['pages_fetched'] === 0;
        }

        $newest = WordpressPost::query()
            ->forSite($site->value)
            ->max('published_at_wp');

        if ($newest !== null) {
            $state->newest_wp_published_at = Carbon::parse($newest);
        }

        $state->last_synced_at = now();
        $state->save();

        return [
            'added' => $added,
            'skipped' => $skipped,
            'new_fetched' => $newFetched,
            'backfill_fetched' => $backfillFetched,
            'error' => null,
        ];
    }

    private function resolveSyncState(WordpressSite $site, int $perPage): WordpressSyncState
    {
        $state = WordpressSyncState::query()->firstOrCreate(['site' => $site->value]);

        if (! $state->wasRecentlyCreated) {
            return $state;
        }

        $count = WordpressPost::query()->forSite($site->value)->count();

        if ($count === 0) {
            return $state;
        }

        $newest = WordpressPost::query()->forSite($site->value)->max('published_at_wp');

        $state->update([
            'newest_wp_published_at' => $newest !== null ? Carbon::parse($newest) : null,
            'backfill_next_page' => max(2, (int) ceil($count / max(1, $perPage)) + 1),
        ]);

        return $state->fresh();
    }

    /**
     * @param  list<array{
     *     wp_post_id: int,
     *     title: string,
     *     excerpt: ?string,
     *     url: string,
     *     featured_image_url: ?string,
     *     published_at_wp: ?Carbon
     * }>  $rows
     * @param  \Illuminate\Support\Collection<int, int>  $existingIds
     * @return array{added: int, skipped: int}
     */
    private function importRows(WordpressSite $site, array $rows, \Illuminate\Support\Collection $existingIds): array
    {
        $added = 0;
        $skipped = 0;
        $now = now();

        foreach ($rows as $row) {
            if ($existingIds->has($row['wp_post_id'])) {
                $skipped++;

                continue;
            }

            WordpressPost::query()->create([
                'site' => $site->value,
                'wp_post_id' => $row['wp_post_id'],
                'title' => $row['title'],
                'excerpt' => $row['excerpt'],
                'url' => $row['url'],
                'featured_image_url' => $row['featured_image_url'],
                'published_at_wp' => $row['published_at_wp'],
                'synced_at' => $now,
            ]);

            $existingIds->put($row['wp_post_id'], true);
            $added++;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * @param  list<string>|null  $sites
     * @return list<WordpressSite>
     */
    private function resolveSites(?array $sites): array
    {
        if ($sites === null || $sites === []) {
            return array_values(array_filter(
                WordpressSite::cases(),
                fn (WordpressSite $site) => $site->isEnabled(),
            ));
        }

        $resolved = [];

        foreach ($sites as $siteKey) {
            $site = WordpressSite::tryFromEnabled((string) $siteKey);

            if ($site !== null) {
                $resolved[] = $site;
            }
        }

        return $resolved;
    }

    private function clamp(int $value, string $key): int
    {
        $limits = config("wordpress_sources.sync.limits.{$key}", ['min' => 1, 'max' => 100]);

        return max((int) $limits['min'], min((int) $limits['max'], $value));
    }
}
