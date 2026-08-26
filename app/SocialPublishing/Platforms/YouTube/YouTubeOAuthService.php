<?php

namespace App\SocialPublishing\Platforms\YouTube;

use App\Models\SocialPlatformAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class YouTubeOAuthService
{
    private const SCOPES = [
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.force-ssl',
    ];

    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => config('social.youtube.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('social.youtube.client_id'),
            'client_secret' => config('social.youtube.client_secret'),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('OAuth YouTube: '.$response->body());
        }

        return $response->json();
    }

    public function storeTokens(array $tokens): SocialPlatformAccount
    {
        $refreshToken = $tokens['refresh_token'] ?? null;

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new RuntimeException(
                'Google no devolvió refresh_token. Revoca el acceso en tu cuenta Google e intenta de nuevo con prompt=consent.'
            );
        }

        return SocialPlatformAccount::updateOrCreate(
            ['platform' => 'youtube'],
            [
                'label' => 'YouTube',
                'credentials' => [
                    'refresh_token' => $refreshToken,
                    'access_token' => $tokens['access_token'] ?? null,
                    'scope' => $tokens['scope'] ?? null,
                ],
                'is_connected' => true,
                'connected_at' => now(),
            ],
        );
    }

    public function disconnect(): void
    {
        SocialPlatformAccount::query()
            ->where('platform', 'youtube')
            ->update([
                'credentials' => null,
                'is_connected' => false,
                'connected_at' => null,
            ]);
    }

    public function isConnected(): bool
    {
        return SocialPlatformAccount::youtubeRefreshToken() !== null;
    }

    public function redirectUri(): string
    {
        return config('social.youtube.redirect_uri')
            ?: url('/auth/youtube/callback');
    }

    public function newState(): string
    {
        return Str::random(40);
    }
}
