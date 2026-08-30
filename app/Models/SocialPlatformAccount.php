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

    /**
     * @return array{
     *     access_token: string,
     *     refresh_token?: ?string,
     *     person_urn?: ?string,
     *     profile_name?: ?string,
     *     token_expires_at?: ?string
     * }|null
     */
    public static function linkedinCredentials(string $platform = 'linkedin'): ?array
    {
        $account = static::query()
            ->where('platform', $platform)
            ->where('is_connected', true)
            ->first();

        $credentials = $account?->credentials;

        if (is_array($credentials) && ! empty($credentials['access_token'])) {
            return [
                'access_token' => (string) $credentials['access_token'],
                'refresh_token' => isset($credentials['refresh_token']) ? (string) $credentials['refresh_token'] : null,
                'person_urn' => isset($credentials['person_urn']) ? (string) $credentials['person_urn'] : null,
                'profile_name' => isset($credentials['profile_name']) ? (string) $credentials['profile_name'] : null,
                'token_expires_at' => isset($credentials['token_expires_at']) ? (string) $credentials['token_expires_at'] : null,
            ];
        }

        $envToken = config("social.platform_accounts.{$platform}.access_token");
        $personUrn = config("social.platform_accounts.{$platform}.person_urn");

        if (is_string($envToken) && $envToken !== '' && is_string($personUrn) && $personUrn !== '') {
            return [
                'access_token' => $envToken,
                'refresh_token' => config("social.platform_accounts.{$platform}.refresh_token"),
                'person_urn' => $personUrn,
            ];
        }

        return null;
    }
}
