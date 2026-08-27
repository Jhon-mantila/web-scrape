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

    /**
     * @return array{page_id: string, page_access_token: string, page_name?: string}|null
     */
    public static function facebookPageCredentials(string $platform): ?array
    {
        $account = static::query()
            ->where('platform', $platform)
            ->where('is_connected', true)
            ->first();

        $credentials = $account?->credentials;

        if (is_array($credentials)
            && ! empty($credentials['page_access_token'])
            && ! empty($credentials['page_id'])) {
            return [
                'page_id' => (string) $credentials['page_id'],
                'page_access_token' => (string) $credentials['page_access_token'],
                'page_name' => isset($credentials['page_name']) ? (string) $credentials['page_name'] : null,
            ];
        }

        $env = config("social.platform_accounts.{$platform}");

        if (is_array($env) && ! empty($env['page_id']) && ! empty($env['page_access_token'])) {
            return [
                'page_id' => (string) $env['page_id'],
                'page_access_token' => (string) $env['page_access_token'],
            ];
        }

        return null;
    }
}
