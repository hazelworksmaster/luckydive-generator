<?php

namespace Tests\Feature;

use App\Services\GenerateNumbers;
use App\Services\Lotto\DrawHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OverdueChainGeneratorTest extends TestCase
{
    use RefreshDatabase;

    /** 메모리 DB에만 세 회차를 저장하고 기준 시각을 고정합니다. */
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2002-12-22 12:00', 'Asia/Seoul'));
        $history = app(DrawHistory::class);
        foreach ([1 => '2002-12-07', 2 => '2002-12-14', 3 => '2002-12-21'] as $round => $date) {
            $history->store([$history->fromArray(['round' => $round, 'date' => $date,
                'number1' => 1, 'number2' => 2, 'number3' => 3, 'number4' => 4, 'number5' => 5, 'number6' => 6, 'bonus' => 45,
            ])], 'initial_csv', true);
        }
    }

    /** 고정 시각을 복원해 다른 알고리즘 테스트에 영향을 주지 않습니다. */
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** CLI 등록과 기본 회차·재현성·명시 저장 경로를 검증합니다. */
    public function test_generation_is_reproducible_and_saves_only_when_requested(): void
    {
        $service = app(GenerateNumbers::class);
        $first = $service->execute(5, null, false, 'overdue-chain-v1');
        self::assertCount(30, array_unique(array_merge(...$first['games'])));
        self::assertSame('disjoint-games-v2', $first['metadata']['selection_rule']);
        self::assertSame(4, $first['draw_no']);
        self::assertSame(3, $first['metadata']['basis_round']);
        self::assertSame([7, 8, 9, 10, 11, 12], $first['metadata']['traces'][0]['chain']);
        self::assertSame($first['games'], $service->execute(5, null, false, 'overdue-chain-v1')['games']);
        $this->assertDatabaseCount('recommendation_runs', 0);
        $this->artisan('lotto:generate', ['--algorithm' => 'overdue-chain-v1', '--dry-run' => true])->assertSuccessful();
        $saved = $service->execute(5, 4, true, 'overdue-chain-v1');
        $this->assertDatabaseHas('recommendation_runs', ['id' => $saved['id'], 'algorithm' => 'overdue-chain-v1']);
    }

    /** 과거 재현은 뒤 회차 정정에 영향받지 않고 보너스도 통계에 사용하지 않습니다. */
    public function test_future_history_and_bonus_do_not_affect_past_result(): void
    {
        $service = app(GenerateNumbers::class);
        $before = $service->execute(5, 2, false, 'overdue-chain-v1');
        DB::table('winning_numbers')->where('round', 3)->update(['number1' => 44]);
        DB::table('winning_numbers')->where('round', 1)->update(['bonus' => 43]);
        $after = $service->execute(5, 2, false, 'overdue-chain-v1');
        self::assertSame($before['games'], $after['games']);
        self::assertSame($before['metadata'], $after['metadata']);
    }

    /** 요청 수량·미래 회차·누락 자료는 저장 전에 실패합니다. */
    public function test_invalid_requests_and_missing_history_fail_without_save(): void
    {
        foreach ([['--count' => 4], ['--draw-no' => 5], ['--draw-no' => 1]] as $options) {
            $this->artisan('lotto:generate', ['--algorithm' => 'overdue-chain-v1', '--save' => true, ...$options])->assertFailed();
        }
        DB::table('winning_numbers')->where('round', 2)->delete();
        $this->artisan('lotto:generate', ['--algorithm' => 'overdue-chain-v1', '--save' => true])->assertFailed();
        $this->assertDatabaseCount('recommendation_runs', 0);
    }
}
