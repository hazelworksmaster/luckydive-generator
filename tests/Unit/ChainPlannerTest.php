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

    /** 직전 게임의 시작 번호 재사용과 2개 중복을 허용하고 전체 반복은 유한합니다. */
    public function test_adjacent_overlap_allows_two_and_finishes_with_unique_games(): void
    {
        $frequency = $missing = array_fill(1, 45, 0);
        $pairs = array_fill(1, 45, array_fill(1, 45, 0));
        $result = (new ChainPlanner)->select($frequency, $missing, $pairs);
        self::assertSame([1, 2], array_slice(array_column($result['traces'], 'seed'), 0, 2));
        self::assertSame([2, 1, 7, 8, 9, 10], $result['traces'][1]['chain']);
        self::assertCount(5, array_unique(array_map('json_encode', $result['games'])));
        self::assertLessThanOrEqual(45, count($result['attempted_seeds']));
        foreach ($result['games'] as $index => $game) {
            self::assertCount(6, array_unique($game));
            self::assertGreaterThanOrEqual(1, min($game));
            self::assertLessThanOrEqual(45, max($game));
            if ($index > 0) {
                self::assertLessThanOrEqual(2, count(array_intersect($game, $result['games'][$index - 1])));
            }
        }
        self::assertGreaterThan(2, count(array_intersect($result['games'][0], $result['games'][2])));
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

    /** 시작 번호의 미출현 동률은 출현 횟수와 작은 번호 순서로 결정합니다. */
    public function test_seed_ties_use_frequency_then_number(): void
    {
        $frequency = $missing = array_fill(1, 45, 0);
        $pairs = array_fill(1, 45, array_fill(1, 45, 0));
        $frequency[10] = $frequency[11] = 100;
        $result = (new ChainPlanner)->select($frequency, $missing, $pairs);
        self::assertSame(10, $result['traces'][0]['seed']);
        self::assertCount(5, $result['games']);
    }

    /** 누락 이력과 중복 번호는 부분 통계로 결과를 만들지 못하게 거부합니다. */
    public function test_invalid_history_fails(): void
    {
        $this->expectException(RuntimeException::class);
        (new ChainPlanner)->build([['round' => 1, 'number1' => 1, 'number2' => 1]], 1);
    }
}
