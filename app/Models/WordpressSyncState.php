<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WordpressSyncState extends Model
{
    protected $fillable = [
        'site',
        'newest_wp_published_at',
        'backfill_next_page',
        'backfill_complete',
        'last_synced_at',
    ];

    protected $casts = [
        'newest_wp_published_at' => 'datetime',
        'backfill_complete' => 'boolean',
        'last_synced_at' => 'datetime',
    ];
}
