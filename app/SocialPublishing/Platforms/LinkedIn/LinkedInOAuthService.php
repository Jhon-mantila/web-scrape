<?php

namespace App\SocialPublishing\Platforms\LinkedIn;

use App\Models\SocialPlatformAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class LinkedInOAuthService
{
    private const SCOPES = [
        'openid',
        'profile',
        'email',
        'w_member_social',
    ];

    private const DEFAULT_TOKEN_TTL_SECONDS = 5_184_000;

    public function __construct(
        private readonly string $accountKey = 'default',
    ) {}

    public function accountKey(): string
    {
        return $this->accountKey;
    }

    public function platformKey(): string
    {
        return $this->accountKey === 'default'
            ? 'linkedin'
            : 'linkedin_'.$this->accountKey;
    }

    public function label(): string
    {
        return (string) config("social.platforms.{$this->platformKey()}.label", $this->platformKey());
    }

    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('social.linkedin.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => implode(' ', self::SCOPES),
        ]);

        return 'https://www.linkedin.com/oauth/v2/authorization?'.$query;
    }

    /**
     * @return array{
     *     access_token: string,
     *     expires_in: int,
     *     refresh_token?: string,
     *     refresh_token_expires_in?: int
     * }
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => config('social.linkedin.client_id'),
            'client_secret' => config('social.linkedin.client_secret'),
        ]);

        if ($response->failed()) {
            throw new RuntimeException('OAuth LinkedIn: '.$response->body());
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('LinkedIn no devolvió access_token.');
        }

        $expiresIn = (int) ($response->json('expires_in') ?? self::DEFAULT_TOKEN_TTL_SECONDS);

        return [
            'access_token' => $accessToken,
            'expires_in' => $expiresIn > 0 ? $expiresIn : self::DEFAULT_TOKEN_TTL_SECONDS,
            'refresh_token' => $response->json('refresh_token'),
            'refresh_token_expires_in' => $response->json('refresh_token_expires_in'),
        ];
    }

    /**
     * @return array{sub: string, name?: string, email?: string}
     */
    public function fetchProfile(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get('https://api.linkedin.com/v2/userinfo');

        if ($response->failed()) {
            throw new RuntimeException('No se pudo leer perfil de LinkedIn: '.$response->body());
        }

        $sub = $response->json('sub');

        if (! is_string($sub) || $sub === '') {
            throw new RuntimeException('LinkedIn no devolvió identificador de perfil (sub).');
        }

        return [
            'sub' => $sub,
            'name' => $response->json('name'),
            'email' => $response->json('email'),
        ];
    }

    /**
     * @param  array{
     *     access_token: string,
     *     expires_in: int,
     *     refresh_token?: string|null,
     *     refresh_token_expires_in?: int|null
     * }  $tokens
     * @param  array{sub: string, name?: string, email?: string}  $profile
     */
    public function storeCredentials(array $tokens, array $profile): SocialPlatformAccount
    {
        $expiresAt = now()->addSeconds($tokens['expires_in']);
        $refreshExpiresAt = isset($tokens['refresh_token_expires_in'])
            ? now()->addSeconds((int) $tokens['refresh_token_expires_in'])
            : null;

        return SocialPlatformAccount::updateOrCreate(
            ['platform' => $this->platformKey()],
            [
                'label' => $this->label(),
                'credentials' => [
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'] ?? null,
                    'person_urn' => 'urn:li:person:'.$profile['sub'],
                    'profile_name' => $profile['name'] ?? null,
                    'profile_email' => $profile['email'] ?? null,
                    'token_expires_at' => $expiresAt->toIso8601String(),
                    'refresh_token_expires_at' => $refreshExpiresAt?->toIso8601String(),
                ],
                'is_connected' => true,
                'connected_at' => now(),
            ],
        );
    }

    public function disconnect(): void
    {
        SocialPlatformAccount::query()
            ->where('platform', $this->platformKey())
            ->update([
                'credentials' => null,
                'is_connected' => false,
                'connected_at' => null,
            ]);
    }

    public function isConnected(): bool
    {
        return SocialPlatformAccount::linkedinCredentials($this->platformKey()) !== null;
    }

    /**
     * @return array{
     *     source: string,
     *     connected_at: ?string,
     *     expires_at: ?string,
     *     days_remaining: ?int,
     *     is_expired: bool,
     *     is_expiring_soon: bool,
     *     profile_name?: ?string,
     *     message?: string
     * }|null
     */
    public function renewalInfo(): ?array
    {
        if (! $this->isConnected()) {
            return null;
        }

        $account = SocialPlatformAccount::query()
            ->where('platform', $this->platformKey())
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
                'message' => 'Token manual en .env. Renueva cada ~60 días en LinkedIn Developers.',
            ];
        }

        $expiresAt = isset($account->credentials['token_expires_at'])
            ? \Illuminate\Support\Carbon::parse($account->credentials['token_expires_at'])
            : $account->connected_at?->copy()->addDays(60);

        if ($expiresAt === null) {
            return null;
        }

        $daysRemaining = (int) now()->startOfDay()->diffInDays($expiresAt->startOfDay(), false);

        return [
            'source' => 'oauth',
            'connected_at' => $account->connected_at?->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'days_remaining' => $daysRemaining,
            'is_expired' => $expiresAt->isPast(),
            'is_expiring_soon' => ! $expiresAt->isPast() && $daysRemaining <= 7,
            'profile_name' => $account->credentials['profile_name'] ?? null,
        ];
    }

    public function redirectUri(): string
    {
        return config('social.linkedin.redirect_uri')
            ?: url('/auth/linkedin/callback');
    }

    public function hasClient(): bool
    {
        return (bool) config('social.linkedin.client_id')
            && (bool) config('social.linkedin.client_secret');
    }

    public function newState(): string
    {
        return Str::random(40);
    }
}
