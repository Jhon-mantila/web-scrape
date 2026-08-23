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
        'model_premium' => env('OLLAMA_MODEL_PREMIUM', 'qwen3:30b-a3b'),
        'premium_min_chars' => (int) env('OLLAMA_PREMIUM_MIN_CHARS', 4500),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 600),
        'format_json' => filter_var(env('OLLAMA_FORMAT_JSON', true), FILTER_VALIDATE_BOOLEAN),
        'temperature' => (float) env('OLLAMA_TEMPERATURE', 0.75),
        'num_ctx' => (int) env('OLLAMA_NUM_CTX', 16384),
        'num_predict' => (int) env('OLLAMA_NUM_PREDICT', 4096),
        'unload_after_generate' => filter_var(env('OLLAMA_UNLOAD_AFTER_GENERATE', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'wordpress' => [
        'url' => env('WORDPRESS_URL'),
        'user' => env('WORDPRESS_USER'),
        'password' => env('WORDPRESS_PASSWORD'),
    ],

    'searxng' => [
        'enabled' => filter_var(env('SEARXNG_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'url' => env('SEARXNG_URL', 'http://searxng:8080'),
        'timeout' => (int) env('SEARXNG_TIMEOUT', 20),
        'language' => env('SEARXNG_LANGUAGE', 'es-ES'),
        'max_queries' => (int) env('SEARXNG_MAX_QUERIES', 3),
        'results_per_query' => (int) env('SEARXNG_RESULTS_PER_QUERY', 3),
    ],

    'comfyui' => [
        'enabled' => filter_var(env('COMFYUI_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'url' => env('COMFYUI_URL', 'http://localhost:8188'),
        'timeout' => (int) env('COMFYUI_TIMEOUT', 180),
        'poll_interval_ms' => (int) env('COMFYUI_POLL_INTERVAL_MS', 500),
        'workflow_path' => env('COMFYUI_WORKFLOW_PATH', resource_path('comfyui/flux-schnell-featured.json')),
        'prompt_node' => env('COMFYUI_PROMPT_NODE', '6'),
        'sampler_node' => env('COMFYUI_SAMPLER_NODE', '3'),
        'latent_node' => env('COMFYUI_LATENT_NODE', '5'),
        'unet_node' => env('COMFYUI_UNET_NODE', '12'),
        'vae_node' => env('COMFYUI_VAE_NODE', '10'),
        'clip_node' => env('COMFYUI_CLIP_NODE', '11'),
        'unet_name' => env('COMFYUI_UNET_NAME', 'flux1-schnell.safetensors'),
        'vae_name' => env('COMFYUI_VAE_NAME', 'ae.safetensors'),
        'clip_name1' => env('COMFYUI_CLIP_NAME1', 'clip_l.safetensors'),
        'clip_name2' => env('COMFYUI_CLIP_NAME2', 't5xxl_fp16.safetensors'),
        'steps' => (int) env('COMFYUI_STEPS', 4),
        'width' => (int) env('COMFYUI_WIDTH', 1216),
        'height' => (int) env('COMFYUI_HEIGHT', 684),
        'prompt_max_chars' => (int) env('COMFYUI_PROMPT_MAX_CHARS', 320),
        'prompt_template' => env(
            'COMFYUI_PROMPT_TEMPLATE',
            'anime illustration, {title}, {category} theme, vibrant colors, cinematic lighting, detailed background, no text, no watermark, no logos'
        ),
        'free_memory_after_images' => filter_var(env('COMFYUI_FREE_MEMORY_AFTER_IMAGES', true), FILTER_VALIDATE_BOOLEAN),
        'free_memory_wait_seconds' => (int) env('COMFYUI_FREE_MEMORY_WAIT_SECONDS', 8),
    ],

    'featured_image' => [
        'watermark_enabled' => filter_var(env('FEATURED_IMAGE_WATERMARK_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'watermark_path' => env('FEATURED_IMAGE_WATERMARK_PATH', storage_path('app/public/Logo/esquina_anime_logo.png')),
        'watermark_position' => env('FEATURED_IMAGE_WATERMARK_POSITION', 'bottom-left'),
        'watermark_margin' => (int) env('FEATURED_IMAGE_WATERMARK_MARGIN', 24),
        'watermark_margin_bottom' => (int) env('FEATURED_IMAGE_WATERMARK_MARGIN_BOTTOM', 0),
        'watermark_width_percent' => (float) env('FEATURED_IMAGE_WATERMARK_WIDTH_PERCENT', 22),
        'watermark_opacity' => (int) env('FEATURED_IMAGE_WATERMARK_OPACITY', 90),
        'watermark_mask_enabled' => filter_var(env('FEATURED_IMAGE_WATERMARK_MASK_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'watermark_mask_opacity' => (int) env('FEATURED_IMAGE_WATERMARK_MASK_OPACITY', 50),
        'watermark_mask_padding' => (int) env('FEATURED_IMAGE_WATERMARK_MASK_PADDING', 14),
        'watermark_mask_full_width' => filter_var(env('FEATURED_IMAGE_WATERMARK_MASK_FULL_WIDTH', true), FILTER_VALIDATE_BOOLEAN),
    ],

];
