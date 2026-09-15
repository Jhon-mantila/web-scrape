<?php

namespace App\Scraper\Support;

use App\Models\News;
use App\Models\NewsAiArticle;
use App\Models\NewsDetail;
use Illuminate\Support\Carbon;

class NewsScraperStats
{
    /**
     * @return array{
     *     total_news: int,
     *     with_details: int,
     *     with_ai: int,
     *     sent_wordpress: int,
     *     pending_wordpress: int,
     *     failed_details: int,
     *     failed_ai: int,
     *     wp_scheduled: int,
     *     wp_published: int,
     *     wp_drafts: int,
     *     wp_legacy: int,
     *     next_scheduled_at: ?string,
     *     last_scheduled_at: ?string,
     *     schedule_buffer_days: ?int
     * }
     */
    public static function compute(): array
    {
        $timezone = (string) config('services.wordpress.schedule_timezone', config('app.timezone', 'UTC'));
        $now = Carbon::now($timezone);

        $scheduledQuery = NewsAiArticle::query()->where('wordpress_status', 'future');
        $lastScheduledRaw = (clone $scheduledQuery)->max('wordpress_scheduled_at');
        $nextScheduledRaw = (clone $scheduledQuery)
            ->where('wordpress_scheduled_at', '>=', $now)
            ->min('wordpress_scheduled_at');

        $lastScheduled = $lastScheduledRaw !== null
            ? Carbon::parse($lastScheduledRaw, $timezone)
            : null;

        $scheduleBufferDays = null;

        if ($lastScheduled !== null && $lastScheduled->isFuture()) {
            $scheduleBufferDays = max(0, (int) ceil($now->diffInHours($lastScheduled) / 24));
        }

        return [
            'total_news' => News::query()->count(),
            'with_details' => NewsDetail::query()->where('status', 'processed')->count(),
            'with_ai' => NewsAiArticle::query()->whereNotNull('body_html')->count(),
            'sent_wordpress' => NewsAiArticle::query()->where('sent_wordpress', true)->count(),
            'pending_wordpress' => NewsAiArticle::query()
                ->where('sent_wordpress', false)
                ->whereNotNull('body_html')
                ->count(),
            'failed_details' => NewsDetail::query()->where('status', 'failed')->count(),
            'failed_ai' => News::query()->where('status_ia', 'failed')->count(),
            'wp_scheduled' => (clone $scheduledQuery)->count(),
            'wp_published' => NewsAiArticle::query()->where('wordpress_status', 'publish')->count(),
            'wp_drafts' => NewsAiArticle::query()->where('wordpress_status', 'draft')->count(),
            'wp_legacy' => NewsAiArticle::query()
                ->where('sent_wordpress', true)
                ->whereNull('wordpress_post_id')
                ->count(),
            'next_scheduled_at' => $nextScheduledRaw !== null
                ? Carbon::parse($nextScheduledRaw, $timezone)->toIso8601String()
                : null,
            'last_scheduled_at' => $lastScheduledRaw !== null
                ? Carbon::parse($lastScheduledRaw, $timezone)->toIso8601String()
                : null,
            'schedule_buffer_days' => $scheduleBufferDays,
        ];
    }
}
