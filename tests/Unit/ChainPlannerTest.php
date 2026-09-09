<?php

namespace Tests\Unit;

use App\Services\Overdue\ChainPlanner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ChainPlannerTest extends TestCase
{
    /** 동반 출현·미출현·빈도·작은 번호 순서의 동률 해결을 각각 검증합니다. */
    public function test_neighbor_priorities_are_deterministic(): void
    {
        foreach ([0, 1, 2, 3] as $case) {
            $frequency = $missing = array_fill(1, 45, 0);
            $pairs = array_fill(1, 45, array_fill(1, 45, 0));
            $frequency[1] = 100;
            $pairs[1][2] = $pairs[1][3] = 10;
            $expected = 2;
            if ($case === 0) {
                $pairs[1][3] = 11;
                $missing[2] = 5;
                $expected = 3;
            } elseif ($case === 1) {
                $missing[3] = 5;
                $frequency[2] = 10;
                $expected = 3;
            } elseif ($case === 2) {
                $frequency[3] = 10;
                $expected = 3;
            }
            $planner = new ChainPlanner;
            $result = $planner->select($frequency, $missing, $pairs);
            self::assertSame(1, $result['traces'][0]['seed']);
            self::assertSame($expected, $result['traces'][0]['chain'][1]);
            self::assertSame($result, $planner->select($frequency, $missing, $pairs));
        }
    }

    /** 강한 6개씩의 궁합 묶음은 중복을 만들지만 다음 후보 묶음으로 넘어가 종료합니다. */
    public function test_duplicate_chains_expand_batches_and_finish_with_bounded_work(): void
    {
        $frequency = $missing = array_fill(1, 45, 0);
        $pairs = array_fill(1, 45, array_fill(1, 45, 0));
        foreach (array_chunk(range(1, 45), 6) as $block) {
            foreach ($block as $left) {
                foreach ($block as $right) {
                    $pairs[$left][$right] = 100;
                }
            }
        }
        $result = (new ChainPlanner)->select($frequency, $missing, $pairs);
        self::assertSame(range(1, 25), $result['attempted_seeds']);
        self::assertSame([1, 1, 2, 2, 3], array_column($result['traces'], 'candidate_batch'));
        self::assertCount(5, array_unique(array_map('json_encode', $result['games'])));
        foreach ($result['games'] as $game) {
            self::assertCount(6, array_unique($game));
            self::assertGreaterThanOrEqual(1, min($game));
            self::assertLessThanOrEqual(45, max($game));
        }
    }

    /** 각 묶음 안에서만 빈도순으로 시작 번호를 고르며 연결 기준은 마지막 선택 번호입니다. */
    public function test_seed_order_and_last_selected_neighbor(): void
    {
        $frequency = $missing = array_fill(1, 45, 0);
        $pairs = array_fill(1, 45, array_fill(1, 45, 0));
        $frequency[10] = 100;
        $frequency[11] = 1000;
        $pairs[10][2] = 50;
        $pairs[2][30] = 50;
        $result = (new ChainPlanner)->select($frequency, $missing, $pairs);
        self::assertSame([10, 2, 30], array_slice($result['traces'][0]['chain'], 0, 3));
    }

    /** 누락 이력과 중복 번호는 부분 통계로 결과를 만들지 못하게 거부합니다. */
    public function test_invalid_history_fails(): void
    {
        $this->expectException(RuntimeException::class);
        (new ChainPlanner)->build([['round' => 1, 'number1' => 1, 'number2' => 1]], 1);
    }
}
