<?php

namespace App\Services\Overdue;

use RuntimeException;

final class ChainPlanner
{
    /** 전체 이력을 한 번 순회하며 보너스를 제외한 빈도·마지막 출현·동반 출현을 계산합니다. */
    public function build(array $rows, int $basis): array
    {
        if ($basis < 1 || count($rows) !== $basis) {
            throw new RuntimeException('1회부터 기준 회차까지 당첨번호를 먼저 수집하세요.');
        }
        $frequency = $last = array_fill(1, 45, 0);
        $pairs = array_fill(1, 45, array_fill(1, 45, 0));
        $canonical = [];
        foreach ($rows as $index => $row) {
            $round = $index + 1;
            if ((int) $row['round'] !== $round) {
                throw new RuntimeException('당첨 이력에 누락 또는 중복 회차가 있습니다.');
            }
            $numbers = [];
            foreach (range(1, 6) as $position) {
                $number = filter_var($row['number'.$position] ?? null, FILTER_VALIDATE_INT);
                if ($number === false || $number < 1 || $number > 45 || in_array($number, $numbers, true)) {
                    throw new RuntimeException('당첨번호는 중복 없는 1~45의 정수 6개여야 합니다.');
                }
                $numbers[] = $number;
                $frequency[$number]++;
                $last[$number] = $round;
            }
            sort($numbers, SORT_NUMERIC);
            $canonical[] = [$round, $numbers];
            for ($i = 0; $i < 6; $i++) {
                for ($j = $i + 1; $j < 6; $j++) {
                    $pairs[$numbers[$i]][$numbers[$j]]++;
                    $pairs[$numbers[$j]][$numbers[$i]]++;
                }
            }
        }
        $missing = array_map(fn ($round) => $basis - $round, $last);
        /** array_map은 단일 입력의 숫자 키를 보존하므로 번호 1~45를 그대로 조회합니다. */
        $result = $this->select($frequency, $missing, $pairs);

        return [...$result, 'draws_hash' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR))];
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
