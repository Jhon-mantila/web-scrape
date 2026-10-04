<?php

namespace App\Scheduling\Contracts;

interface ScheduledTaskHandler
{
    public function key(): string;

    public function label(): string;

    public function description(): string;

    public function sortOrder(): int;

    /**
     * @return array{processed: int, published: int, failed: int, skipped: int, message?: string}
     */
    public function run(): array;

    /**
     * @return array{
     *     enabled: bool,
     *     frequency: string,
     *     interval_hours: int,
     *     daily_at: string,
     *     timezone: string
     * }
     */
    public function defaultConfig(): array;
}
