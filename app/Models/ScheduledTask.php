<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledTask extends Model
{
    protected $fillable = [
        'task_key',
        'label',
        'description',
        'enabled',
        'frequency',
        'interval_hours',
        'daily_at',
        'once_at',
        'timezone',
        'sort_order',
        'last_run_at',
        'last_run_summary',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'once_at' => 'datetime',
        'last_run_at' => 'datetime',
        'last_run_summary' => 'array',
    ];
}
