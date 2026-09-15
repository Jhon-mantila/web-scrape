<?php

namespace App\Models;

use App\SocialPublishing\Enums\PublicationStatus;
use App\SocialPublishing\Enums\WordpressSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WordpressPost extends Model
{
    protected $fillable = [
        'site',
        'wp_post_id',
        'title',
        'excerpt',
        'url',
        'featured_image_url',
        'published_at_wp',
        'synced_at',
    ];

    protected $casts = [
        'published_at_wp' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function publications(): HasMany
    {
        return $this->hasMany(SocialArticlePublication::class);
    }

    public function siteEnum(): ?WordpressSite
    {
        return WordpressSite::tryFrom($this->site);
    }

    public function siteLabel(): string
    {
        return $this->siteEnum()?->label() ?? $this->site;
    }

    public function facebookPlatform(): ?string
    {
        return $this->siteEnum()?->facebookPlatform();
    }

    /**
     * @param  Builder<WordpressPost>  $query
     * @return Builder<WordpressPost>
     */
    public function scopeForSite(Builder $query, string $site): Builder
    {
        return $query->where('site', $site);
    }

    /**
     * @param  Builder<WordpressPost>  $query
     * @return Builder<WordpressPost>
     */
    public function scopePendingPlatform(Builder $query, string $platform): Builder
    {
        return $query->whereDoesntHave('publications', function (Builder $publications) use ($platform) {
            $publications
                ->where('platform', $platform)
                ->whereIn('status', [
                    PublicationStatus::Published,
                    PublicationStatus::Scheduled,
                    PublicationStatus::Publishing,
                ]);
        });
    }
}
