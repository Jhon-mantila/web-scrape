<?php

namespace App\Http\Controllers;

use App\SocialPublishing\Platforms\YouTube\YouTubeOAuthService;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(YouTubeOAuthService $oauth): Response
    {
        return Inertia::render('Settings/Index', [
            'youtube' => [
                'connected' => $oauth->isConnected(),
                'channel_id' => config('social.youtube.channel_id'),
                'redirect_uri' => $oauth->redirectUri(),
                'has_client' => (bool) config('social.youtube.client_id'),
            ],
        ]);
    }
}
