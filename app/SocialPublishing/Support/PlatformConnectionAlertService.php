<?php

namespace App\SocialPublishing\Support;

use App\SocialPublishing\Platforms\Facebook\FacebookOAuthService;
use App\SocialPublishing\Platforms\LinkedIn\LinkedInOAuthService;
use App\SocialPublishing\Platforms\YouTube\YouTubeOAuthService;

class PlatformConnectionAlertService
{
    /**
     * @return list<array{
     *     platform: string,
     *     label: string,
     *     message: string,
     *     settings_url: string
     * }>
     */
    public function collect(): array
    {
        $alerts = [];

        $youtube = app(YouTubeOAuthService::class);

        if (
            $this->platformEnabled('youtube')
            && ! config('social.platforms.youtube.coming_soon')
            && filled(config('social.youtube.client_id'))
            && ! $youtube->isConnected()
        ) {
            $alerts[] = [
                'platform' => 'youtube',
                'label' => 'YouTube',
                'message' => 'No está conectado. Conecta OAuth en Configuración para publicar videos.',
                'settings_url' => route('settings.index'),
            ];
        }

        foreach (['default' => 'linkedin', 'jessika' => 'linkedin_jessika'] as $account => $platformKey) {
            if (! $this->platformEnabled($platformKey)) {
                continue;
            }

            $oauth = new LinkedInOAuthService($account);

            if (! $oauth->hasClient() || $oauth->isConnected()) {
                continue;
            }

            $alerts[] = [
                'platform' => $platformKey,
                'label' => $oauth->label(),
                'message' => 'No está conectado. Conecta OAuth en Configuración para publicar.',
                'settings_url' => route('settings.index'),
            ];
        }

        foreach (['esquinaweb', 'esquinagamers'] as $account) {
            $oauth = new FacebookOAuthService($account);
            $platformKey = $oauth->platformKey();

            if (! $this->platformEnabled($platformKey)) {
                continue;
            }

            if (! $oauth->hasClient() || $oauth->isConnected()) {
                continue;
            }

            $alerts[] = [
                'platform' => $platformKey,
                'label' => $oauth->label(),
                'message' => 'No está conectada. Conecta la página en Configuración para publicar.',
                'settings_url' => route('settings.index'),
            ];
        }

        return $alerts;
    }

    private function platformEnabled(string $platformKey): bool
    {
        return (bool) config("social.platforms.{$platformKey}.enabled", false);
    }
}
