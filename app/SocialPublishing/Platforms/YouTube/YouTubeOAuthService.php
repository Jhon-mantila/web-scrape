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

        $renewalDays = (int) config('social.youtube.refresh_token_renewal_days', 7);

        $refreshExpiresAt = isset($tokens['refresh_token_expires_in'])
            ? now()->addSeconds(max(1, (int) $tokens['refresh_token_expires_in']))
            : ($renewalDays > 0 ? now()->addDays($renewalDays) : null);

        $accessExpiresAt = isset($tokens['expires_in'])
            ? now()->addSeconds(max(1, (int) $tokens['expires_in']))
            : null;

        return SocialPlatformAccount::updateOrCreate(
            ['platform' => 'youtube'],
            [
                'label' => 'YouTube',
                'credentials' => [
                    'refresh_token' => $refreshToken,
                    'access_token' => $tokens['access_token'] ?? null,
                    'scope' => $tokens['scope'] ?? null,
                    'token_expires_at' => $refreshExpiresAt?->toIso8601String(),
                    'access_token_expires_at' => $accessExpiresAt?->toIso8601String(),
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

    /**
     * @return array{
     *     source: string,
     *     connected_at: ?string,
     *     expires_at: ?string,
     *     days_remaining: ?int,
     *     is_expired: bool,
     *     is_expiring_soon: bool,
     *     message?: string
     * }|null
     */
    public function renewalInfo(): ?array
    {
        if (! $this->isConnected()) {
            return null;
        }

        $account = SocialPlatformAccount::query()
            ->where('platform', 'youtube')
            ->where('is_connected', true)
            ->first();

        if ($account === null) {
            return [
                'source' => 'env',
                'connected_at' => null,
                'expires_at' => null,
                'days_remaining' => null,
                'is_expired' => false,
                'is_expiring_soon' => false,
                'message' => 'Refresh token manual en .env. Reconecta en Configuración si deja de subir videos.',
            ];
        }

        $renewalDays = (int) config('social.youtube.refresh_token_renewal_days', 7);

        $expiresAt = isset($account->credentials['token_expires_at'])
            ? \Illuminate\Support\Carbon::parse($account->credentials['token_expires_at'])
            : ($renewalDays > 0 ? $account->connected_at?->copy()->addDays($renewalDays) : null);

        if ($expiresAt === null) {
            return [
                'source' => 'oauth',
                'connected_at' => $account->connected_at?->toIso8601String(),
                'expires_at' => null,
                'days_remaining' => null,
                'is_expired' => false,
                'is_expiring_soon' => false,
                'message' => 'Refresh token sin cadencia fija (app Google en Production). Reconecta solo si falla la subida.',
            ];
        }

        $daysRemaining = (int) now()->startOfDay()->diffInDays($expiresAt->startOfDay(), false);

        return [
            'source' => 'oauth',
            'connected_at' => $account->connected_at?->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'days_remaining' => $daysRemaining,
            'is_expired' => $expiresAt->isPast(),
            'is_expiring_soon' => ! $expiresAt->isPast() && $daysRemaining <= 7,
        ];
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
