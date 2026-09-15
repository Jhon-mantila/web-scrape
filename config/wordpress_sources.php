<?php

return [

    'sites' => [
        'esquinaweb' => [
            'label' => 'Esquina Web',
            'url' => env('WORDPRESS_ESQUINAWEB_URL'),
            'facebook_platform' => 'facebook_esquinaweb',
            'enabled' => filter_var(env('WORDPRESS_ESQUINAWEB_ENABLED', true), FILTER_VALIDATE_BOOL),
            'coming_soon' => false,
        ],
        'esquinagamers' => [
            'label' => 'Esquina Gamers',
            'url' => env('WORDPRESS_ESQUINAGAMERS_URL'),
            'facebook_platform' => 'facebook_esquinagamers',
            'enabled' => filter_var(env('WORDPRESS_ESQUINAGAMERS_ENABLED', true), FILTER_VALIDATE_BOOL),
            'coming_soon' => false,
        ],
        'esquinaanime' => [
            'label' => 'Esquina Anime',
            'url' => env('WORDPRESS_ESQUINAANIME_URL'),
            'facebook_platform' => null,
            'enabled' => false,
            'coming_soon' => true,
        ],
    ],

    'sync' => [
        'per_page' => (int) env('WORDPRESS_SYNC_PER_PAGE', 20),
        'backfill_pages' => (int) env('WORDPRESS_SYNC_BACKFILL_PAGES', 5),
        'new_pages' => (int) env('WORDPRESS_SYNC_NEW_PAGES', 3),
        'limits' => [
            'per_page' => [
                'min' => 1,
                'max' => (int) env('WORDPRESS_SYNC_PER_PAGE_MAX', 100),
            ],
            'backfill_pages' => [
                'min' => 1,
                'max' => (int) env('WORDPRESS_SYNC_BACKFILL_PAGES_MAX', 20),
            ],
            'new_pages' => [
                'min' => 1,
                'max' => (int) env('WORDPRESS_SYNC_NEW_PAGES_MAX', 10),
            ],
        ],
    ],

    'linkedin_platforms' => ['linkedin', 'linkedin_jessika'],

];
