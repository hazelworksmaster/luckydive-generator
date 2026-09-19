<?php

namespace Tests\Unit;

use App\Services\Frequent\SpreadPlanner;
use PHPUnit\Framework\TestCase;

class SpreadPlannerTest extends TestCase
{
    /** 전체 번호 동률·궁합 0회에서도 시작 번호를 예약하고 30개를 유일하게 만듭니다. */
    public function test_reserved_seeds_and_zero_pairs_complete_disjoint_games(): void
    {
        $frequency = array_fill(1, 45, 0);
        $pairs = array_fill(1, 45, array_fill(1, 45, 0));
        $planner = new SpreadPlanner;
        $result = $planner->select($frequency, $pairs);
        self::assertSame([1, 2, 3, 4, 5], $result['seeds']);
        self::assertSame([1, 6, 7, 8, 9, 10], $result['traces'][0]['chain']);
        self::assertSame([5, 26, 27, 28, 29, 30], $result['traces'][4]['chain']);
        self::assertCount(5, $result['games']);
        self::assertCount(30, array_unique(array_merge(...$result['games'])));
        self::assertSame($result, $planner->select($frequency, $pairs));
        foreach ($result['games'] as $index => $game) {
            self::assertCount(6, $game);
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
        self::assertCount(30, array_unique(array_merge(...$result['games'])));
    }
}
