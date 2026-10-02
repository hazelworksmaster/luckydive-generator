<?php

namespace Tests\Unit;

use App\Algorithms\Rules\PreviousGameOverlap;
use PHPUnit\Framework\TestCase;

class PreviousGameOverlapTest extends TestCase
{
    /** 첫 게임과 중복 0·1·2개를 허용하고 경계인 3개부터 거부합니다. */
    public function test_overlap_boundary_and_first_game(): void
    {
        $rule = new PreviousGameOverlap;
        self::assertTrue($rule->allows([1, 2, 3, 4, 5, 6], []));
        foreach (range(0, 6) as $shared) {
            $game = array_merge(array_slice([1, 2, 3, 4, 5, 6], 0, $shared), array_slice([7, 8, 9, 10, 11, 12], 0, 6 - $shared));
            self::assertSame($shared <= 2, $rule->allows($game, [[1, 2, 3, 4, 5, 6]]));
        }
    }

    /** 첫 게임과 3개가 겹쳐도 직전 게임과 1개만 겹치면 허용합니다. */
    public function test_only_immediately_previous_accepted_game_is_compared(): void
    {
        $rule = new PreviousGameOverlap;
        $accepted = [[1, 2, 3, 4, 5, 6], [1, 2, 7, 8, 9, 11]];
        self::assertTrue($rule->allows([1, 5, 6, 12, 13, 14], $accepted));
        self::assertFalse($rule->allows([7, 8, 9, 12, 13, 14], $accepted));
    }
}
