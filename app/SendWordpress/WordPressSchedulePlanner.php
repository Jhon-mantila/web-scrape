<?php

namespace App\SendWordpress;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class WordPressSchedulePlanner
{
    /** @var array<string, int> */
    private array $batchCounts = [];

    /** @var array<string, int> */
    private array $wordpressCounts = [];

    private readonly string $timezone;

    private readonly int $maxPerDay;

    private readonly int $startHour;

    private readonly int $startMinute;

    private readonly int $intervalMaxHours;

    private readonly ?int $intervalMinHours;

    public function __construct(private readonly WordPressClient $client)
    {
        $this->timezone = (string) config('services.wordpress.schedule_timezone', config('app.timezone', 'UTC'));
        $this->maxPerDay = max(1, (int) config('services.wordpress.schedule_max_per_day', 5));
        $this->startHour = max(0, min(23, (int) config('services.wordpress.schedule_start_hour', 9)));
        $this->startMinute = max(0, min(59, (int) config('services.wordpress.schedule_start_minute', 0)));
        $this->intervalMaxHours = max(1, (int) config('services.wordpress.schedule_interval_hours', 3));
        $min = config('services.wordpress.schedule_interval_min_hours');
        $this->intervalMinHours = $min !== null && $min !== '' ? max(1, (int) $min) : null;
    }

    public function nextScheduledAt(): Carbon
    {
        $day = Carbon::now($this->timezone)->startOfDay();
        $safety = 0;

        while ($safety++ < 3660) {
            $used = $this->countForDate($day);

            if ($used >= $this->maxPerDay) {
                $day->addDay();

                continue;
            }

            $scheduled = $this->nextAvailableSlotOnDay($day, $used);

            if ($scheduled === null) {
                $day->addDay();

                continue;
            }

            $dateKey = $day->toDateString();
            $this->batchCounts[$dateKey] = ($this->batchCounts[$dateKey] ?? 0) + 1;

            Log::info('wordpress: slot de programación asignado', [
                'date' => $dateKey,
                'posts_on_day' => $used + 1,
                'max_per_day' => $this->maxPerDay,
                'scheduled_at' => $scheduled->toIso8601String(),
            ]);

            return $scheduled;
        }

        return Carbon::now($this->timezone)->addDay();
    }

    private function nextAvailableSlotOnDay(Carbon $day, int $usedOnDay): ?Carbon
    {
        for ($slot = $usedOnDay; $slot < $this->maxPerDay; $slot++) {
            $scheduled = $this->slotDateTime($day, $slot);

            if (! $scheduled->isPast()) {
                return $scheduled;
            }
        }

        return null;
    }

    private function slotDateTime(Carbon $day, int $slotIndex): Carbon
    {
        $offsetHours = 0;

        for ($i = 0; $i < $slotIndex; $i++) {
            $offsetHours += $this->intervalHoursForSlot($i);
        }

        return $day->copy()
            ->setTime($this->startHour, $this->startMinute)
            ->addHours($offsetHours);
    }

    private function intervalHoursForSlot(int $slotIndex): int
    {
        if ($this->intervalMinHours !== null && $this->intervalMinHours < $this->intervalMaxHours) {
            return random_int($this->intervalMinHours, $this->intervalMaxHours);
        }

        return $this->intervalMaxHours;
    }

    private function countForDate(Carbon $day): int
    {
        $dateKey = $day->toDateString();

        if (! array_key_exists($dateKey, $this->wordpressCounts)) {
            $this->wordpressCounts[$dateKey] = $this->fetchWordpressCountForDay($day);
        }

        return $this->wordpressCounts[$dateKey] + ($this->batchCounts[$dateKey] ?? 0);
    }

    private function fetchWordpressCountForDay(Carbon $day): int
    {
        try {
            $from = $day->copy()->startOfDay();
            $to = $day->copy()->endOfDay();

            return count($this->client->listPostsInDateRange($from, $to));
        } catch (\Throwable $e) {
            Log::warning('wordpress: no se pudo consultar posts programados/publicados', [
                'date' => $day->toDateString(),
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
