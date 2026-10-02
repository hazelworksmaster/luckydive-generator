<?php

namespace App\Services\Overdue;

use App\Algorithms\Rules\PreviousGameOverlap;
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

    /** 미출현 순으로 시작하고 직전 게임과 최대 2개 중복을 허용하며 유한 단계로 연결합니다. */
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
        $games = $traces = $seen = $attempted = [];
        $overlap = new PreviousGameOverlap;
        foreach ($overdue as $seed) {
            $attempted[] = $seed;
            $chain = [$seed];
            $used = [$seed => true];
            /** 게임 내 번호는 재사용하지 않으며 시작 번호를 포함해 직전 게임 중복을 계산합니다. */
            for ($step = 1; $step < 6; $step++) {
                foreach ($neighbors[$chain[$step - 1]] as $next) {
                    if (! isset($used[$next]) && $overlap->allows([...$chain, $next], $games)) {
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
            /** 더 앞선 게임과 일부 중복은 허용하지만 완전히 같은 조합은 다음 시작 번호로 넘어갑니다. */
            $key = implode('-', $game);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $games[] = $game;
            $traces[] = ['seed' => $seed, 'chain' => $chain];
            if (count($games) === 5) {
                return ['games' => $games, 'traces' => $traces, 'attempted_seeds' => $attempted];
            }
        }
        throw new RuntimeException('직전 게임 중복 제한을 만족하는 서로 다른 5게임을 만들지 못했습니다.');
    }
}
