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
            'hints' => [
                'max_video_gb' => 2,
                'content_type' => 'Video de página (no Reel)',
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
            'hints' => [
                'max_video_gb' => 2,
                'content_type' => 'Video de página (no Reel)',
            ],
        ],
        'linkedin' => [
            'label' => 'LinkedIn — Jhon',
            'icon' => 'linkedin',
            'enabled' => true,
            'caption' => [
                'model' => env('SOCIAL_LINKEDIN_MODEL', env('OLLAMA_MODEL', 'gemma4:12b')),
                'max_chars' => 3000,
            ],
            'hints' => [
                'scheduling' => false,
                'thumbnail' => false,
                'max_video_gb' => 5,
                'max_duration_minutes' => 10,
            ],
        ],
        'linkedin_jessika' => [
            'label' => 'LinkedIn — Jessika',
            'icon' => 'linkedin',
            'enabled' => true,
            'caption' => [
                'model' => env('SOCIAL_LINKEDIN_JESSIKA_MODEL', env('OLLAMA_MODEL', 'gemma4:12b')),
                'max_chars' => 3000,
            ],
            'hints' => [
                'scheduling' => false,
                'thumbnail' => false,
                'max_video_gb' => 5,
                'max_duration_minutes' => 10,
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
        /** Plazo para reconectar si la app OAuth de Google está en modo Testing (7 d). En Production pon 0. */
        'refresh_token_renewal_days' => (int) env('YOUTUBE_REFRESH_TOKEN_RENEWAL_DAYS', 7),
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
        'linkedin' => [
            'access_token' => env('LINKEDIN_ACCESS_TOKEN'),
            'refresh_token' => env('LINKEDIN_REFRESH_TOKEN'),
            'person_urn' => env('LINKEDIN_PERSON_URN'),
        ],
        'linkedin_jessika' => [
            'access_token' => env('LINKEDIN_JESSIKA_ACCESS_TOKEN'),
            'refresh_token' => env('LINKEDIN_JESSIKA_REFRESH_TOKEN'),
            'person_urn' => env('LINKEDIN_JESSIKA_PERSON_URN'),
        ],
    ],

    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect_uri' => env(
            'LINKEDIN_REDIRECT_URI',
            env('APP_URL', 'http://localhost:8000').'/auth/linkedin/callback'
        ),
        'api_version' => env('LINKEDIN_API_VERSION', '202608'),
    ],

    'facebook' => [
        'max_video_gb' => 2,
        'normalize_video' => filter_var(env('FACEBOOK_NORMALIZE_VIDEO', true), FILTER_VALIDATE_BOOL),

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
