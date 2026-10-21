<?php

namespace App\SocialPublishing\Platforms\YouTube;

use App\Models\SocialPlatformAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YouTubeAccessToken
{
    public function get(): string
    {
        $refreshToken = SocialPlatformAccount::youtubeRefreshToken();

        if ($refreshToken === null) {
            throw new RuntimeException('YouTube no conectado. Ve a Configuración y conecta tu canal.');
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('social.youtube.client_id'),
            'client_secret' => config('social.youtube.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('No se pudo refrescar token de YouTube: '.$response->body());
        }

        return (string) $response->json('access_token');
    }
}
