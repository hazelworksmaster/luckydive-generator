<?php

namespace App\Algorithms;

use App\Algorithms\Contracts\NumberGenerator;
use InvalidArgumentException;
use Random\Engine\Secure;
use Random\Randomizer;

final class RandomGenerator implements NumberGenerator
{
    /** 암호학적 난수로 각 6개 조합을 동일한 확률로 선택합니다. */
    public function identifier(): string
    {
        return 'random-v1';
    }

    /** 1~45의 전체 조합을 저장하지 않고 직접 추출하며 이번 결과 안의 중복만 제거합니다.
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

        while (count($games) < $count) {
            $game = array_map(fn (int $key): int => $numbers[$key], $random->pickArrayKeys($numbers, 6));
            sort($game, SORT_NUMERIC);
            $key = implode('-', $game);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $games[] = $game;
        }

        return $games;
    }
}
