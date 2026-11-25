<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';

        $testingConfigCache = sys_get_temp_dir().'/laravel-testing-config-cache-missing.php';
        putenv('APP_CONFIG_CACHE='.$testingConfigCache);
        $_ENV['APP_CONFIG_CACHE'] = $testingConfigCache;
        $_SERVER['APP_CONFIG_CACHE'] = $testingConfigCache;

        putenv('DB_CONNECTION=sqlite');
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_CONNECTION'] = 'sqlite';

        putenv('DB_DATABASE=:memory:');
        $_ENV['DB_DATABASE'] = ':memory:';
        $_SERVER['DB_DATABASE'] = ':memory:';

        putenv('DB_HOST=127.0.0.1');
        $_ENV['DB_HOST'] = '127.0.0.1';
        $_SERVER['DB_HOST'] = '127.0.0.1';

        putenv('DB_URL');
        unset($_ENV['DB_URL'], $_SERVER['DB_URL']);

        putenv('CACHE_STORE=array');
        $_ENV['CACHE_STORE'] = 'array';
        $_SERVER['CACHE_STORE'] = 'array';

        putenv('SESSION_DRIVER=array');
        $_ENV['SESSION_DRIVER'] = 'array';
        $_SERVER['SESSION_DRIVER'] = 'array';

        putenv('QUEUE_CONNECTION=sync');
        $_ENV['QUEUE_CONNECTION'] = 'sync';
        $_SERVER['QUEUE_CONNECTION'] = 'sync';

        parent::setUp();

        $connection = (string) config('database.default');
        $database = (string) config('database.connections.'.$connection.'.database');

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            $this->fail(
                'ABORTADO: los tests iban a usar '.$connection.' / '.$database
                .' en lugar de sqlite :memory:. No se toca MySQL.'
            );
        }
    }
}
