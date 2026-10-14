<?php

namespace App\SocialPublishing\Support;

use App\SocialPublishing\Platforms\Facebook\FacebookOAuthService;
use App\SocialPublishing\Platforms\LinkedIn\LinkedInOAuthService;
use App\SocialPublishing\Platforms\YouTube\YouTubeOAuthService;

class PlatformTokenAlertService
{
    /**
     * @return list<array{
     *     platform: string,
     *     label: string,
     *     message: string,
     *     expires_at: ?string,
     *     source: string,
     *     settings_url: string
     * }>
     */
    public function collect(): array
    {
        $byPlatform = [];

        $this->mergeRenewalAlert(
            $byPlatform,
            'youtube',
            'YouTube',
            app(YouTubeOAuthService::class)->renewalInfo(),
        );

        foreach (['default' => 'linkedin', 'jessika' => 'linkedin_jessika'] as $account => $platformKey) {
            $oauth = new LinkedInOAuthService($account);
            $label = $oauth->label();
            $this->mergeRenewalAlert($byPlatform, $platformKey, $label, $oauth->renewalInfo());
        }

        foreach (['esquinaweb', 'esquinagamers'] as $account) {
            $oauth = new FacebookOAuthService($account);
            $this->mergeRenewalAlert(
                $byPlatform,
                $oauth->platformKey(),
                $oauth->label(),
                $oauth->renewalInfo(),
            );
        }

        foreach (PlatformTokenSessionAlerts::all() as $platformKey => $sessionAlert) {
            if (isset($byPlatform[$platformKey])) {
                continue;
            }

            $byPlatform[$platformKey] = [
                'platform' => $platformKey,
                'label' => (string) ($sessionAlert['label'] ?? $platformKey),
                'message' => (string) ($sessionAlert['message'] ?? 'Token inválido o vencido.'),
                'expires_at' => null,
                'source' => (string) ($sessionAlert['source'] ?? 'api_error'),
                'settings_url' => route('settings.index'),
            ];
        }

        return array_values($byPlatform);
    }

    /**
     * @param  array<string, array<string, mixed>>  $target
     * @param  array<string, mixed>|null  $renewal
     */
    private function mergeRenewalAlert(array &$target, string $platformKey, string $label, ?array $renewal): void
    {
        if ($renewal === null || ! ($renewal['is_expired'] ?? false)) {
            return;
        }

        $expiresAt = isset($renewal['expires_at']) ? (string) $renewal['expires_at'] : null;

        $target[$platformKey] = [
            'platform' => $platformKey,
            'label' => $label,
            'message' => 'Token OAuth vencido. Reconecta la cuenta en Configuración para volver a publicar.',
            'expires_at' => $expiresAt,
            'source' => 'renewal',
            'settings_url' => route('settings.index'),
        ];
    }
}
