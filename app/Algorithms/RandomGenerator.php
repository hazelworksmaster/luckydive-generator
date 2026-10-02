<?php

namespace App\Algorithms;

use App\Algorithms\Contracts\NumberGenerator;
use App\Algorithms\Rules\PreviousGameOverlap;
use InvalidArgumentException;
use Random\Engine\Secure;
use Random\Randomizer;
use RuntimeException;

final class RandomGenerator implements NumberGenerator
{
    /** 암호학적 난수로 후보를 뽑고 공통 중복 규칙을 만족하는 조합을 선택합니다. */
    public function identifier(): string
    {
        return 'random-v1';
    }

    /** 1~45에서 직접 추출하고 동일 조합 및 직전 게임과 3개 이상 겹치는 후보를 제외합니다.
     * @return list<list<int>>
     */
    public function generate(int $count): array
    {
        if ($count < 1 || $count > 100) {
            throw new InvalidArgumentException('게임 수는 1~100 사이여야 합니다.');
        }

        $random = new Randomizer(new Secure);
        $numbers = range(1, 45);
        $games = [];
        $seen = [];
        $overlap = new PreviousGameOverlap;
        $attempts = 0;

        while (count($games) < $count) {
            /** 후보 재추출에 상한을 두며 실패 시 부분 결과를 반환하거나 저장하지 않습니다. */
            if (++$attempts > 10000) {
                throw new RuntimeException('중복 제한을 만족하는 랜덤 조합을 만들지 못했습니다. 다시 실행하세요.');
            }
            $game = array_map(fn (int $key): int => $numbers[$key], $random->pickArrayKeys($numbers, 6));
            sort($game, SORT_NUMERIC);
            $key = implode('-', $game);
            if (isset($seen[$key]) || ! $overlap->allows($game, $games)) {
                continue;
            }
            $seen[$key] = true;
            $games[] = $game;
        }

        return $games;
    }
}
