<?php

namespace App\Services\Overdue;

use RuntimeException;

final class ChainPlanner
{
    /** 전체 이력을 한 번 순회하며 보너스를 제외한 빈도·마지막 출현·동반 출현을 계산합니다. */
    public function build(array $rows, int $basis): array
    {
        $statistics = (new \App\Services\Lotto\NumberHistoryStatistics)->build($rows, $basis);
        $result = $this->select($statistics['frequency'], $statistics['missing'], $statistics['pairs']);

        return [...$result, 'draws_hash' => $statistics['draws_hash']];
    }

    /** 미출현 순으로 시작하고 5게임 전체에서 사용한 번호를 제외하며 유한 단계로 연결합니다. */
    public function select(array $frequency, array $missing, array $pairs): array
    {
        $overdue = range(1, 45);
        usort($overdue, fn ($a, $b) => ($missing[$b] <=> $missing[$a]) ?: ($frequency[$b] <=> $frequency[$a]) ?: ($a <=> $b));
        $neighbors = [];
        foreach (range(1, 45) as $number) {
            $order = array_values(array_diff(range(1, 45), [$number]));
            usort($order, fn ($a, $b) => ($pairs[$number][$b] <=> $pairs[$number][$a])
                ?: ($missing[$b] <=> $missing[$a]) ?: ($frequency[$b] <=> $frequency[$a]) ?: ($a <=> $b));
            $neighbors[$number] = $order;
        }
        $games = $traces = $used = $attempted = [];
        foreach ($overdue as $seed) {
            if (isset($used[$seed])) {
                continue;
            }
            $attempted[] = $seed;
            $chain = [$seed];
            $used[$seed] = true;
            /** 사용 집합을 게임 사이에도 유지해 시작 번호와 궁합수 모두 재사용하지 않습니다. */
            for ($step = 1; $step < 6; $step++) {
                foreach ($neighbors[$chain[$step - 1]] as $next) {
                    if (! isset($used[$next])) {
                        $chain[] = $next;
                        $used[$next] = true;
                        break;
                    }
                }
                if (count($chain) !== $step + 1) {
                    throw new RuntimeException('연결할 수 있는 번호가 없습니다.');
                }
            }
            $game = $chain;
            sort($game, SORT_NUMERIC);
            $games[] = $game;
            $traces[] = ['seed' => $seed, 'chain' => $chain];
            if (count($games) === 5) {
                return ['games' => $games, 'traces' => $traces, 'attempted_seeds' => $attempted];
            }
        }
        throw new RuntimeException('사용하지 않은 번호로 5게임을 만들지 못했습니다.');
    }
}
