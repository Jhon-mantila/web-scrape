<?php

namespace App\Wordpress;

use App\Models\SocialArticlePublication;
use App\Models\WordpressPost;
use App\SocialPublishing\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Builder;

class WordpressArticleStats
{
    /**
     * @return array{
     *     total_articles: int,
     *     published: int,
     *     scheduled: int,
     *     failed: int,
     *     publishing: int,
     *     pending_slots: int
     * }
     */
    public static function compute(?string $site = null): array
    {
        $articlesQuery = WordpressPost::query();

        if ($site !== null && $site !== '') {
            $articlesQuery->forSite($site);
        }

        $articleIds = (clone $articlesQuery)->pluck('id');

        $publicationsQuery = SocialArticlePublication::query()
            ->when($site !== null && $site !== '', fn (Builder $query) => $query->whereHas(
                'post',
                fn (Builder $post) => $post->where('site', $site),
            ));

        $stats = [
            'total_articles' => $articleIds->count(),
            'published' => (clone $publicationsQuery)->where('status', PublicationStatus::Published)->count(),
            'scheduled' => (clone $publicationsQuery)->where('status', PublicationStatus::Scheduled)->count(),
            'failed' => (clone $publicationsQuery)->where('status', PublicationStatus::Failed)->count(),
            'publishing' => (clone $publicationsQuery)->where('status', PublicationStatus::Publishing)->count(),
            'pending_slots' => 0,
        ];

        if ($articleIds->isEmpty()) {
            return $stats;
        }

        $posts = WordpressPost::query()
            ->when($site !== null && $site !== '', fn (Builder $query) => $query->forSite($site))
            ->with('publications')
            ->get();

        $pendingSlots = 0;

        foreach ($posts as $post) {
            $siteEnum = $post->siteEnum();
            $allowed = $siteEnum?->allowedPlatforms() ?? [];
            $byPlatform = $post->publications->keyBy('platform');

            foreach ($allowed as $platform) {
                $publication = $byPlatform->get($platform);

                if ($publication === null || ! $publication->blocksRepublish()) {
                    $pendingSlots++;
                }
            }
        }

        $stats['pending_slots'] = $pendingSlots;

        return $stats;
    }
}
