<?php

namespace App\SendWordpress;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class WordPressDailyPostQuota
{
    /** @var array<string, int> */
    private array $batchCounts = [];

    /** @var array<string, int> */
    private array $wordpressCounts = [];

    public function __construct(private readonly WordPressClient $client) {}

    public function timezone(): string
    {
        return (string) config('services.wordpress.schedule_timezone', config('app.timezone', 'UTC'));
    }

    public function maxPerDay(): int
    {
        $max = config('services.wordpress.max_posts_per_day');

        if ($max !== null && $max !== '') {
            return max(1, (int) $max);
        }

        return max(1, (int) config('services.wordpress.schedule_max_per_day', 3));
    }

    public function countForDate(Carbon $day): int
    {
        $dateKey = $day->copy()->timezone($this->timezone())->toDateString();

        if (! array_key_exists($dateKey, $this->wordpressCounts)) {
            $this->wordpressCounts[$dateKey] = $this->fetchWordpressCountForDay($day);
        }

        return $this->wordpressCounts[$dateKey] + ($this->batchCounts[$dateKey] ?? 0);
    }

    public function remainingForDate(Carbon $day): int
    {
        return max(0, $this->maxPerDay() - $this->countForDate($day));
    }

    public function hasCapacityOn(Carbon $day): bool
    {
        return $this->remainingForDate($day) > 0;
    }

    public function hasCapacityToday(): bool
    {
        $today = Carbon::now($this->timezone())->startOfDay();

        return $this->hasCapacityOn($today);
    }

    public function registerPostOn(Carbon $when): void
    {
        $dateKey = $when->copy()->timezone($this->timezone())->toDateString();
        $this->batchCounts[$dateKey] = ($this->batchCounts[$dateKey] ?? 0) + 1;
    }

    private function fetchWordpressCountForDay(Carbon $day): int
    {
        try {
            $localized = $day->copy()->timezone($this->timezone());
            $from = $localized->copy()->startOfDay();
            $to = $localized->copy()->endOfDay();

            return count($this->client->listPostsInDateRange($from, $to));
        } catch (\Throwable $e) {
            Log::warning('wordpress: no se pudo consultar cupo diario', [
                'date' => $day->toDateString(),
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
