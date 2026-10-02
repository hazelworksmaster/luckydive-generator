<?php

namespace App\Algorithms\Rules;

final class PreviousGameOverlap
{
    public const MAX_SHARED_NUMBERS = 2;

    /** 이번 생성 결과의 직전 게임만 비교하며 첫 게임에는 중복 제한을 적용하지 않습니다. */
    public function allows(array $candidate, array $acceptedGames): bool
    {
        if ($acceptedGames === []) {
            return true;
        }
        $previous = $acceptedGames[array_key_last($acceptedGames)];

        return count(array_intersect($candidate, $previous)) <= self::MAX_SHARED_NUMBERS;
    }
}
