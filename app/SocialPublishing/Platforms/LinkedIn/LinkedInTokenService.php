<?php

namespace App\SocialPublishing\Platforms\LinkedIn;

use App\Models\SocialPlatformAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LinkedInTokenService
{
    public function __construct(
        private readonly string $platformKey,
    ) {}

    public function accessToken(): string
    {
        $credentials = SocialPlatformAccount::linkedinCredentials($this->platformKey);

        if ($credentials === null) {
            throw new RuntimeException("LinkedIn ({$this->platformKey}) no conectado. Ve a Configuración y conecta el perfil.");
        }

        $expiresAt = isset($credentials['token_expires_at'])
            ? \Illuminate\Support\Carbon::parse($credentials['token_expires_at'])
            : null;

        if ($expiresAt !== null && $expiresAt->isFuture() && $expiresAt->gt(now()->addMinutes(5))) {
            return $credentials['access_token'];
        }

        $refreshToken = $credentials['refresh_token'] ?? null;

        if (! is_string($refreshToken) || $refreshToken === '') {
            if ($expiresAt !== null && $expiresAt->isPast()) {
                throw new RuntimeException('Token de LinkedIn vencido. Vuelve a conectar en Configuración.');
            }

            return $credentials['access_token'];
        }

        return $this->refreshAccessToken($refreshToken);
    }

    public function personUrn(): string
    {
        $credentials = SocialPlatformAccount::linkedinCredentials($this->platformKey);
        $urn = $credentials['person_urn'] ?? null;

        if (! is_string($urn) || $urn === '') {
            throw new RuntimeException('LinkedIn sin person_urn. Vuelve a conectar en Configuración.');
        }

        return $urn;
    }

    /**
     * @return array<string, string>
     */
    public function apiHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->accessToken(),
            'Linkedin-Version' => (string) config('social.linkedin.api_version', '202608'),
            'X-Restli-Protocol-Version' => '2.0.0',
            'Content-Type' => 'application/json',
        ];
    }

    private function refreshAccessToken(string $refreshToken): string
    {
        $response = LinkedInHttpClient::oauthForm()
            ->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => config('social.linkedin.client_id'),
            'client_secret' => config('social.linkedin.client_secret'),
        ]);

        if ($response->failed()) {
            throw new RuntimeException('No se pudo refrescar token de LinkedIn: '.$response->body());
        }

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('LinkedIn no devolvió access_token al refrescar.');
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 5_184_000);
        $expiresAt = now()->addSeconds($expiresIn > 0 ? $expiresIn : 5_184_000);

        $account = SocialPlatformAccount::query()
            ->where('platform', $this->platformKey)
            ->where('is_connected', true)
            ->first();

        if ($account !== null) {
            $credentials = $account->credentials ?? [];
            $credentials['access_token'] = $accessToken;
            $credentials['token_expires_at'] = $expiresAt->toIso8601String();

            if ($response->json('refresh_token')) {
                $credentials['refresh_token'] = $response->json('refresh_token');
            }

            $account->update(['credentials' => $credentials]);
        }

        return $accessToken;
    }
}
