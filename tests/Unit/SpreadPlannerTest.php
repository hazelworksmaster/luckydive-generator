<?php

namespace Tests\Unit;

use App\Services\Frequent\SpreadPlanner;
use PHPUnit\Framework\TestCase;

class SpreadPlannerTest extends TestCase
{
    /** 시작 번호 예약을 유지하고 직전 중복 2개와 비인접 게임의 번호 재사용을 허용합니다. */
    public function test_reserved_seeds_and_adjacent_overlap_with_zero_pairs(): void
    {
        $frequency = array_fill(1, 45, 0);
        $pairs = array_fill(1, 45, array_fill(1, 45, 0));
        $planner = new SpreadPlanner;
        $result = $planner->select($frequency, $pairs);
        self::assertSame([1, 2, 3, 4, 5], $result['seeds']);
        self::assertSame([1, 6, 7, 8, 9, 10], $result['traces'][0]['chain']);
        self::assertSame([2, 6, 7, 11, 12, 13], $result['traces'][1]['chain']);
        self::assertGreaterThan(2, count(array_intersect($result['games'][0], $result['games'][2])));
        self::assertCount(5, $result['games']);
        self::assertCount(5, array_unique(array_map('serialize', $result['games'])));
        self::assertSame($result, $planner->select($frequency, $pairs));
        foreach ($result['games'] as $index => $game) {
            self::assertCount(6, array_unique($game));
            if ($index > 0) {
                self::assertCount(2, array_intersect($game, $result['games'][$index - 1]));
            }
            self::assertSame([$result['seeds'][$index]], array_values(array_intersect($game, $result['seeds'])));
        }
    }

    /** 빈도 상위 시작 번호와 마지막 번호 기준의 낮은 궁합·빈도·번호 순위를 검증합니다. */
    public function test_frequency_seeds_and_low_pair_priorities(): void
    {
        $frequency = array_fill(1, 45, 0);
        $pairs = array_fill(1, 45, array_fill(1, 45, 10));
        foreach ([45, 44, 43, 42, 41] as $index => $seed) {
            $frequency[$seed] = 100 - $index;
        }
        $pairs[45][1] = $pairs[45][2] = $pairs[45][3] = 0;
        $frequency[1] = 1;
        $pairs[2][30] = 0;
        $result = (new SpreadPlanner)->select($frequency, $pairs);
        self::assertSame([45, 44, 43, 42, 41], $result['seeds']);
        self::assertSame([45, 2, 30], array_slice($result['traces'][0]['chain'], 0, 3));
        self::assertCount(5, array_unique(array_map('serialize', $result['games'])));
    }
}
