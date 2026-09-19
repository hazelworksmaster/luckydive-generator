<?php

namespace App\Services\Frequent;

use App\Services\Lotto\NumberHistoryStatistics;
use RuntimeException;

final class SpreadPlanner
{
    /** 다른 통계 생성기와 같은 이력 검증·집계를 사용하며 보너스는 제외합니다. */
    public function build(array $rows, int $basis): array
    {
        $statistics = (new NumberHistoryStatistics)->build($rows, $basis);

        return [...$this->select($statistics['frequency'], $statistics['pairs']),
            'draws_hash' => $statistics['draws_hash']];
    }

    /** 상위 5개 시작 번호를 예약하고 게임 전체에서 번호를 재사용하지 않습니다. */
    public function select(array $frequency, array $pairs): array
    {
        $ranked = range(1, 45);
        usort($ranked, fn ($a, $b) => ($frequency[$b] <=> $frequency[$a]) ?: ($a <=> $b));
        $seeds = array_slice($ranked, 0, 5);
        $used = array_fill_keys($seeds, true);
        $neighbors = [];
        foreach (range(1, 45) as $number) {
            $order = array_values(array_diff(range(1, 45), [$number]));
            /** 동반 출현이 같으면 전체 출현이 적은 번호, 작은 번호 순으로 고릅니다. */
            usort($order, fn ($a, $b) => ($pairs[$number][$a] <=> $pairs[$number][$b])
                ?: ($frequency[$a] <=> $frequency[$b]) ?: ($a <=> $b));
            $neighbors[$number] = $order;
        }
        $games = $traces = [];
        foreach ($seeds as $seed) {
            $chain = [$seed];
            for ($step = 1; $step < 6; $step++) {
                foreach ($neighbors[$chain[$step - 1]] as $next) {
                    if (! isset($used[$next])) {
                        $chain[] = $next;
                        $used[$next] = true;
                        break;
                    }
                }
                if (count($chain) !== $step + 1) {
                    throw new RuntimeException('연결할 수 있는 미사용 번호가 없습니다.');
                }
            }
            $game = $chain;
            sort($game, SORT_NUMERIC);
            $games[] = $game;
            $traces[] = ['seed' => $seed, 'chain' => $chain];
        }

        return ['games' => $games, 'seeds' => $seeds, 'traces' => $traces];
    }
}
