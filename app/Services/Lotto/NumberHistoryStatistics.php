<?php

namespace App\Services\Lotto;

use RuntimeException;

final class NumberHistoryStatistics
{
    /** 완전한 정규 당첨 이력을 검증하고 번호 빈도와 동반 출현을 한 번에 집계합니다. */
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
        return ['frequency' => $frequency, 'missing' => $missing, 'pairs' => $pairs,
            'draws_hash' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR))];
    }
}
