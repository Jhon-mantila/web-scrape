<?php

/**
 * Se ejecuta ANTES de arrancar Laravel. Evita que phpunit.xml / .env.testing
 * pierdan contra bootstrap/cache/config.php (MySQL real + migrate:fresh).
 */
$dummyConfigCache = sys_get_temp_dir().'/laravel-testing-config-cache-missing.php';

$forced = [
    'APP_ENV' => 'testing',
    'APP_CONFIG_CACHE' => $dummyConfigCache,
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
];

foreach ($forced as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

unset($_ENV['DB_URL'], $_SERVER['DB_URL']);
putenv('DB_URL');

require __DIR__.'/../vendor/autoload.php';
