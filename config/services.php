<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen3:14b'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 600),
        'format_json' => filter_var(env('OLLAMA_FORMAT_JSON', true), FILTER_VALIDATE_BOOLEAN),
        'temperature' => (float) env('OLLAMA_TEMPERATURE', 0.75),
        'num_ctx' => (int) env('OLLAMA_NUM_CTX', 16384),
        'num_predict' => (int) env('OLLAMA_NUM_PREDICT', 4096),
    ],

    'wordpress' => [
        'url' => env('WORDPRESS_URL'),
        'user' => env('WORDPRESS_USER'),
        'password' => env('WORDPRESS_PASSWORD'),
    ],

];
