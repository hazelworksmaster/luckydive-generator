<?php

namespace Tests\Feature;

use Tests\TestCase;

class BootstrapTest extends TestCase
{
    /** 초기화가 실제 DB 없이 실행되고 한국 시간과 테스트 격리를 유지하는지 확인합니다. */
    public function test_bootstrap_is_isolated(): void
    {
        $this->assertSame('Asia/Seoul', config('app.timezone'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('list')->assertSuccessful();
    }
}
