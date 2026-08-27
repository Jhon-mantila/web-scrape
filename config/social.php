<?php

return [

    'platforms' => [
        'youtube' => [
            'label' => 'YouTube',
            'icon' => 'youtube',
            'enabled' => true,
            'caption' => [
                'model' => env('SOCIAL_YOUTUBE_MODEL', env('OLLAMA_MODEL', 'gemma4:12b')),
                'max_chars' => 5000,
            ],
        ],
        'facebook_esquinaweb' => [
            'label' => 'Facebook — Esquinaweb',
            'icon' => 'facebook',
            'enabled' => true,
            'caption' => [
                'model' => env('SOCIAL_FACEBOOK_ESQUINAWEB_MODEL', env('OLLAMA_MODEL', 'gemma4:12b')),
                'max_chars' => 500,
            ],
        ],
        'facebook_esquinagamers' => [
            'label' => 'Facebook — Esquinagamers',
            'icon' => 'facebook',
            'enabled' => true,
            'caption' => [
                'model' => env('SOCIAL_FACEBOOK_ESQUINAGAMERS_MODEL', env('OLLAMA_MODEL', 'gemma4:12b')),
                'max_chars' => 500,
            ],
        ],
        'tiktok' => [
            'label' => 'TikTok',
            'icon' => 'tiktok',
            'enabled' => false,
            'coming_soon' => true,
            'caption' => [
                'model' => env('SOCIAL_TIKTOK_MODEL', env('OLLAMA_MODEL', 'gemma4:12b')),
                'max_chars' => 2200,
            ],
        ],
    ],

    'upload' => [
        'max_video_mb' => (int) env('SOCIAL_MAX_VIDEO_MB', 500),
        'video_mimes' => ['mp4', 'mov', 'webm'],
        'thumbnail_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

    'youtube' => [
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect_uri' => env('YOUTUBE_REDIRECT_URI', env('APP_URL', 'http://localhost:8000').'/auth/youtube/callback'),
        'channel_id' => env('YOUTUBE_CHANNEL_ID', 'UCosCkg76aWQ6r3-n9AvQePw'),
    ],

    'platform_accounts' => [
        'youtube' => [
            'refresh_token' => env('YOUTUBE_REFRESH_TOKEN'),
        ],
        'facebook_esquinaweb' => [
            'page_id' => env('FACEBOOK_ESQUINAWEB_PAGE_ID'),
            'page_access_token' => env('FACEBOOK_ESQUINAWEB_PAGE_TOKEN'),
        ],
        'facebook_esquinagamers' => [
            'page_id' => env('FACEBOOK_ESQUINAGAMERS_PAGE_ID'),
            'page_access_token' => env('FACEBOOK_ESQUINAGAMERS_PAGE_TOKEN'),
        ],
    ],

    'facebook' => [
        'esquinaweb' => [
            'app_id' => env('FACEBOOK_ESQUINAWEB_APP_ID'),
            'app_secret' => env('FACEBOOK_ESQUINAWEB_APP_SECRET'),
            'page_id' => env('FACEBOOK_ESQUINAWEB_PAGE_ID'),
            'page_access_token' => env('FACEBOOK_ESQUINAWEB_PAGE_TOKEN'),
            'redirect_uri' => env(
                'FACEBOOK_ESQUINAWEB_REDIRECT_URI',
                env('APP_URL', 'http://localhost:8000').'/auth/facebook/esquinaweb/callback'
            ),
        ],
        'esquinagamers' => [
            'app_id' => env('FACEBOOK_ESQUINAGAMERS_APP_ID'),
            'app_secret' => env('FACEBOOK_ESQUINAGAMERS_APP_SECRET'),
            'page_id' => env('FACEBOOK_ESQUINAGAMERS_PAGE_ID'),
            'page_access_token' => env('FACEBOOK_ESQUINAGAMERS_PAGE_TOKEN'),
            'redirect_uri' => env(
                'FACEBOOK_ESQUINAGAMERS_REDIRECT_URI',
                env('APP_URL', 'http://localhost:8000').'/auth/facebook/esquinagamers/callback'
            ),
        ],
    ],

];
