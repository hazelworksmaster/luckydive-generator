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
            $missing[1] = 100;
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

    /** 이미 사용한 시작 번호를 건너뛰고 정확히 5번의 연결로 중복 없는 30개를 만듭니다. */
    public function test_used_seeds_are_skipped_and_five_disjoint_games_finish(): void
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
        self::assertSame([1, 7, 13, 19, 25], $result['attempted_seeds']);
        self::assertCount(30, array_unique(array_merge(...$result['games'])));
        self::assertCount(5, array_unique(array_map('json_encode', $result['games'])));
        foreach ($result['games'] as $game) {
            self::assertCount(6, array_unique($game));
            self::assertGreaterThanOrEqual(1, min($game));
            self::assertLessThanOrEqual(45, max($game));
        }
    }

    /** 출현 횟수보다 미출현 기간으로 시작 번호를 고르고 마지막 번호로 연결합니다. */
    public function test_seed_order_and_last_selected_neighbor(): void
    {
        $frequency = $missing = array_fill(1, 45, 0);
        $pairs = array_fill(1, 45, array_fill(1, 45, 0));
        $frequency[10] = 100;
        $missing[10] = 10;
        $frequency[11] = 1000;
        $pairs[10][2] = 50;
        $pairs[2][30] = 50;
        $result = (new ChainPlanner)->select($frequency, $missing, $pairs);
        self::assertSame([10, 2, 30], array_slice($result['traces'][0]['chain'], 0, 3));
    }

    /** 궁합수가 과거 게임에 있으면 다음 순위로 내려가며 동반 출현 0회도 사용합니다. */
    public function test_used_neighbors_are_skipped_and_zero_pairs_fill_remaining_games(): void
    {
        $frequency = $missing = array_fill(1, 45, 0);
        $pairs = array_fill(1, 45, array_fill(1, 45, 0));
        $pairs[7][1] = 100;
        $pairs[7][8] = 50;
        $result = (new ChainPlanner)->select($frequency, $missing, $pairs);
        self::assertSame([7, 8], array_slice($result['traces'][1]['chain'], 0, 2));
        self::assertSame(array_chunk(range(1, 30), 6), $result['games']);
        self::assertCount(5, $result['attempted_seeds']);
    }

    /** 시작 번호의 미출현 동률은 출현 횟수와 작은 번호 순서로 결정합니다. */
    public function test_seed_ties_use_frequency_then_number(): void
    {
        $frequency = $missing = array_fill(1, 45, 0);
        $pairs = array_fill(1, 45, array_fill(1, 45, 0));
        $frequency[10] = $frequency[11] = 100;
        $result = (new ChainPlanner)->select($frequency, $missing, $pairs);
        self::assertSame(10, $result['traces'][0]['seed']);
        self::assertCount(30, array_unique(array_merge(...$result['games'])));
    }

    /** 누락 이력과 중복 번호는 부분 통계로 결과를 만들지 못하게 거부합니다. */
    public function test_invalid_history_fails(): void
    {
        $this->expectException(RuntimeException::class);
        (new ChainPlanner)->build([['round' => 1, 'number1' => 1, 'number2' => 1]], 1);
    }
}
