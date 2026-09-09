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

    /** 준비된 번호별 통계에서 최대 45개 시작 번호와 각 5단계만 순회합니다. */
    public function select(array $frequency, array $missing, array $pairs): array
    {
        $overdue = range(1, 45);
        usort($overdue, fn ($a, $b) => ($missing[$b] <=> $missing[$a]) ?: ($a <=> $b));
        $neighbors = [];
        foreach (range(1, 45) as $number) {
            $order = array_values(array_diff(range(1, 45), [$number]));
            usort($order, fn ($a, $b) => ($pairs[$number][$b] <=> $pairs[$number][$a])
                ?: ($missing[$b] <=> $missing[$a]) ?: ($frequency[$b] <=> $frequency[$a]) ?: ($a <=> $b));
            $neighbors[$number] = $order;
        }
        $games = $traces = $seen = $attempted = [];
        foreach (array_chunk($overdue, 10) as $batch => $candidates) {
            usort($candidates, fn ($a, $b) => ($frequency[$b] <=> $frequency[$a]) ?: ($a <=> $b));
            foreach ($candidates as $seed) {
                $attempted[] = $seed;
                $chain = [$seed];
                $used = [$seed => true];
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
                $key = implode('-', $game);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $games[] = $game;
                $traces[] = ['seed' => $seed, 'candidate_batch' => $batch + 1, 'chain' => $chain];
                if (count($games) === 5) {
                    return ['games' => $games, 'traces' => $traces, 'attempted_seeds' => $attempted];
                }
            }
        }
        throw new RuntimeException('모든 시작 번호를 확인했지만 서로 다른 5조합을 만들지 못했습니다.');
    }
}
