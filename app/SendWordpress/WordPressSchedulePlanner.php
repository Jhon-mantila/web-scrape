<?php

namespace App\SendWordpress;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class WordPressSchedulePlanner
{
    private readonly int $startHour;

    private readonly int $startMinute;

    private readonly int $intervalMaxHours;

    private readonly ?int $intervalMinHours;

    public function __construct(
        private readonly WordPressDailyPostQuota $quota,
    ) {
        $this->startHour = max(0, min(23, (int) config('services.wordpress.schedule_start_hour', 9)));
        $this->startMinute = max(0, min(59, (int) config('services.wordpress.schedule_start_minute', 0)));
        $this->intervalMaxHours = max(1, (int) config('services.wordpress.schedule_interval_hours', 3));
        $min = config('services.wordpress.schedule_interval_min_hours');
        $this->intervalMinHours = $min !== null && $min !== '' ? max(1, (int) $min) : null;
    }

    public function nextScheduledAt(): Carbon
    {
        $timezone = $this->quota->timezone();
        $day = Carbon::now($timezone)->startOfDay();
        $safety = 0;

        while ($safety++ < 3660) {
            $used = $this->quota->countForDate($day);

            if ($used >= $this->quota->maxPerDay()) {
                $day->addDay();

                continue;
            }

            $scheduled = $this->nextAvailableSlotOnDay($day, $used);

            if ($scheduled === null) {
                $day->addDay();

                continue;
            }

            $this->quota->registerPostOn($scheduled);

            Log::info('wordpress: slot de programación asignado', [
                'date' => $day->toDateString(),
                'posts_on_day' => $used + 1,
                'max_per_day' => $this->quota->maxPerDay(),
                'scheduled_at' => $scheduled->toIso8601String(),
            ]);

            return $scheduled;
        }

        return Carbon::now($timezone)->addDay();
    }

    private function nextAvailableSlotOnDay(Carbon $day, int $usedOnDay): ?Carbon
    {
        for ($slot = $usedOnDay; $slot < $this->quota->maxPerDay(); $slot++) {
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
}
