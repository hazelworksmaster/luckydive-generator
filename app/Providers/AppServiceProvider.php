<?php

namespace App\Providers;

use App\Algorithms\Contracts\NumberGenerator;
use App\Algorithms\GeneratorRegistry;
use App\Contracts\Lotto\LottoDrawClient;
use App\Services\Lotto\DhlotteryLottoDrawClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** 기본 생성 계약도 같은 등록 목록을 통해 해석합니다. */
    public function register(): void
    {
        $this->app->bind(LottoDrawClient::class, DhlotteryLottoDrawClient::class);
        $this->app->bind(NumberGenerator::class, fn ($app) => $app->make(GeneratorRegistry::class)->resolve());
    }

    /** 부팅 시 DB나 외부 API에 접속하지 않습니다. */
    public function boot(): void {}
}
