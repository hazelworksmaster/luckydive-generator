<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** 실제 DB 접속을 막고 테스트를 격리된 메모리 DB로 고정합니다. */
    public function createApplication()
    {
        foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => ''] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $app = parent::createApplication();
        if ($app['config']['database.default'] !== 'sqlite' || $app['config']['database.connections.sqlite.database'] !== ':memory:') {
            throw new \RuntimeException('테스트는 SQLite 메모리 DB에서만 실행할 수 있습니다.');
        }

        return $app;
    }
}
