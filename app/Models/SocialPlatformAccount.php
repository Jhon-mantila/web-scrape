<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialPlatformAccount extends Model
{
    protected $fillable = [
        'platform',
        'label',
        'credentials',
        'is_connected',
        'connected_at',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'is_connected' => 'boolean',
        'connected_at' => 'datetime',
    ];

    public static function youtubeRefreshToken(): ?string
    {
        $account = static::query()
            ->where('platform', 'youtube')
            ->where('is_connected', true)
            ->first();

        $token = $account?->credentials['refresh_token'] ?? null;

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $envToken = config('social.platform_accounts.youtube.refresh_token');

        return is_string($envToken) && $envToken !== '' ? $envToken : null;
    }
}
