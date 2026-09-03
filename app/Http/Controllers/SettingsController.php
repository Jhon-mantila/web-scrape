<?php

namespace App\Http\Controllers;

use App\SocialPublishing\Platforms\Facebook\FacebookOAuthService;
use App\SocialPublishing\Platforms\LinkedIn\LinkedInOAuthService;
use App\SocialPublishing\Platforms\YouTube\YouTubeOAuthService;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(YouTubeOAuthService $youtubeOAuth): Response
    {
        return Inertia::render('Settings/Index', [
            'youtube' => [
                'connected' => $youtubeOAuth->isConnected(),
                'channel_id' => config('social.youtube.channel_id'),
                'redirect_uri' => $youtubeOAuth->redirectUri(),
                'has_client' => (bool) config('social.youtube.client_id'),
                'renewal' => $youtubeOAuth->renewalInfo(),
            ],
            'linkedin' => collect(['default', 'jessika'])
                ->map(function (string $account) {
                    $oauth = new LinkedInOAuthService($account);

                    return [
                        'account' => $account,
                        'label' => $oauth->label(),
                        'connected' => $oauth->isConnected(),
                        'redirect_uri' => $oauth->redirectUri(),
                        'has_client' => $oauth->hasClient(),
                        'renewal' => $oauth->renewalInfo(),
                    ];
                })
                ->values()
                ->all(),
            'facebook' => collect(['esquinaweb', 'esquinagamers'])
                ->map(function (string $account) {
                    $oauth = new FacebookOAuthService($account);

                    return [
                        'account' => $account,
                        'label' => $oauth->label(),
                        'connected' => $oauth->isConnected(),
                        'page_id' => $oauth->expectedPageId(),
                        'redirect_uri' => $oauth->redirectUri(),
                        'has_client' => $oauth->hasClient(),
                        'renewal' => $oauth->renewalInfo(),
                    ];
                })
                ->values()
                ->all(),
        ]);
    }
}
