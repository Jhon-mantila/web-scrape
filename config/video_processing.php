<?php

return [

    'output' => [
        'width' => (int) env('VIDEO_SPLIT_OUTPUT_WIDTH', 1080),
        'height' => (int) env('VIDEO_SPLIT_OUTPUT_HEIGHT', 1920),
    ],

    'clip' => [
        'min_duration_seconds' => (int) env('VIDEO_SPLIT_MIN_SECONDS', 15),
        'max_duration_seconds' => (int) env('VIDEO_SPLIT_MAX_SECONDS', 60),
        'max_target_count' => (int) env('VIDEO_SPLIT_MAX_TARGET_COUNT', 30),
    ],

    'heartbeat_timeout_seconds' => (int) env('VIDEO_WORKER_HEARTBEAT_TIMEOUT', 120),

    'worker_concurrency' => 1,

    'storage' => [
        'analysis_disk' => 'local',
        'clips_disk' => 'public',
        'clips_directory' => 'social-clips',
        'originals_directory' => 'social-videos',
    ],

];
