<?php

namespace App\SocialPublishing\Platforms\Facebook;

use App\Models\SocialPlatformAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class FacebookOAuthService
{
    private const SCOPES = [
        'pages_manage_posts',
        'pages_show_list',
        'pages_read_engagement',
    ];

    /** Duración típica del token de larga duración de Meta (~60 días). */
    private const DEFAULT_TOKEN_TTL_SECONDS = 5_184_000;

    public function __construct(
        private readonly string $configKey,
    ) {}

    public function configKey(): string
    {
        return $this->configKey;
    }

    public function platformKey(): string
    {
        return 'facebook_'.$this->configKey;
    }

    public function label(): string
    {
        return (string) config("social.platforms.{$this->platformKey()}.label", $this->platformKey());
    }

    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->appId(),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => implode(',', self::SCOPES),
            'response_type' => 'code',
        ]);

        return 'https://www.facebook.com/v21.0/dialog/oauth?'.$query;
    }

    /**
     * @return array{access_token: string, expires_in: int}
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::get('https://graph.facebook.com/v21.0/oauth/access_token', [
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('OAuth Facebook (token corto): '.$response->body());
        }

        $shortToken = $response->json('access_token');

        if (! is_string($shortToken) || $shortToken === '') {
            throw new RuntimeException('Facebook no devolvió access_token.');
        }

        $longResponse = Http::get('https://graph.facebook.com/v21.0/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'fb_exchange_token' => $shortToken,
        ]);

        if ($longResponse->failed()) {
            throw new RuntimeException('OAuth Facebook (token largo): '.$longResponse->body());
        }

        $longToken = $longResponse->json('access_token');

        if (! is_string($longToken) || $longToken === '') {
            throw new RuntimeException('Facebook no devolvió token de larga duración.');
        }

        $expiresIn = (int) ($longResponse->json('expires_in') ?? self::DEFAULT_TOKEN_TTL_SECONDS);

        if ($expiresIn <= 0) {
            $expiresIn = self::DEFAULT_TOKEN_TTL_SECONDS;
        }

        return [
            'access_token' => $longToken,
            'expires_in' => $expiresIn,
        ];
    }

    /**
     * @return array{page_id: string, page_name: string, page_access_token: string}
     */
    public function resolvePageToken(string $userToken): array
    {
        $expectedPageId = $this->expectedPageId();

        if ($expectedPageId === null) {
            throw new RuntimeException(
                "Configura FACEBOOK_{$this->envPrefix()}_PAGE_ID en .env antes de conectar."
            );
        }

        $pages = $this->fetchManagedPages($userToken);

        foreach ($pages as $page) {
            if ((string) ($page['id'] ?? '') === $expectedPageId) {
                $token = $page['access_token'] ?? null;

                if (! is_string($token) || $token === '') {
                    throw new RuntimeException('Facebook no devolvió token de la página.');
                }

                return [
                    'page_id' => (string) $page['id'],
                    'page_name' => (string) ($page['name'] ?? $expectedPageId),
                    'page_access_token' => $token,
                ];
            }
        }

        if ($pages === []) {
            throw new RuntimeException(
                'Facebook no devolvió ninguna página para tu cuenta. '
                .'Inicia sesión con el perfil que administra la página, '
                .'acepta los permisos de páginas en el diálogo de Meta '
                .'y verifica que la app tenga el caso de uso "Administrar todo en tu página".'
            );
        }

        $available = collect($pages)
            ->map(fn (array $page) => sprintf('%s (%s)', $page['name'] ?? 'Sin nombre', $page['id'] ?? '?'))
            ->implode(', ');

        throw new RuntimeException(
            "Tu cuenta no administra la página {$expectedPageId}. "
            ."Páginas disponibles con esta sesión: {$available}. "
            .'Corrige FACEBOOK_'.$this->envPrefix().'_PAGE_ID en .env o conéctate con otra cuenta.'
        );
    }

    /**
     * @return list<array{id?: string, name?: string, access_token?: string}>
     */
    private function fetchManagedPages(string $userToken): array
    {
        $pages = [];
        $url = 'https://graph.facebook.com/v21.0/me/accounts';

        do {
            $response = Http::get($url, [
                'access_token' => $userToken,
                'fields' => 'id,name,access_token',
                'limit' => 100,
            ]);

            if ($response->failed()) {
                throw new RuntimeException('No se pudieron listar páginas de Facebook: '.$response->body());
            }

            $payload = $response->json();
            $batch = $payload['data'] ?? [];

            if (is_array($batch)) {
                foreach ($batch as $page) {
                    if (is_array($page)) {
                        $pages[] = $page;
                    }
                }
            }

            $next = $payload['paging']['next'] ?? null;
            $url = is_string($next) ? $next : null;
        } while ($url !== null);

        return $pages;
    }

    /**
     * @param  array{page_id: string, page_name: string, page_access_token: string}  $page
     */
    public function storePageCredentials(array $page, int $expiresIn): SocialPlatformAccount
    {
        $expiresAt = now()->addSeconds($expiresIn);

        return SocialPlatformAccount::updateOrCreate(
            ['platform' => $this->platformKey()],
            [
                'label' => $this->label(),
                'credentials' => array_merge($page, [
                    'token_expires_at' => $expiresAt->toIso8601String(),
                ]),
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
        return SocialPlatformAccount::facebookPageCredentials($this->platformKey()) !== null;
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
            ->where('platform', $this->platformKey())
            ->where('is_connected', true)
            ->first();

        if ($account !== null) {
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
            ];
        }

        return [
            'source' => 'env',
            'connected_at' => null,
            'expires_at' => null,
            'days_remaining' => null,
            'is_expired' => false,
            'is_expiring_soon' => false,
            'message' => 'Token manual en .env. Meta suele exigir renovarlo cada ~60 días.',
        ];
    }

    public function redirectUri(): string
    {
        return config("social.facebook.{$this->configKey}.redirect_uri")
            ?: url("/auth/facebook/{$this->configKey}/callback");
    }

    public function hasClient(): bool
    {
        return $this->appId() !== null && $this->appSecret() !== null;
    }

    public function expectedPageId(): ?string
    {
        $pageId = config("social.facebook.{$this->configKey}.page_id");

        return is_string($pageId) && $pageId !== '' ? $pageId : null;
    }

    public function newState(): string
    {
        return Str::random(40);
    }

    private function appId(): ?string
    {
        $value = config("social.facebook.{$this->configKey}.app_id");

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function appSecret(): ?string
    {
        $value = config("social.facebook.{$this->configKey}.app_secret");

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function envPrefix(): string
    {
        return strtoupper($this->configKey);
    }
}
