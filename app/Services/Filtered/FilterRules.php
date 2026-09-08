<?php

namespace App\Services\Filtered;

final class FilterRules
{
    public const BASE_VERSION = 'base-v1';

    /** 전체 8,145,060개 조합을 메모리에 적재하지 않고 순차 생성합니다. */
    public function combinations(): \Generator
    {
        $id = 0;
        for ($a = 1; $a <= 40; $a++) {
            for ($b = $a + 1; $b <= 41; $b++) {
                for ($c = $b + 1; $c <= 42; $c++) {
                    for ($d = $c + 1; $d <= 43; $d++) {
                        for ($e = $d + 1; $e <= 44; $e++) {
                            for ($f = $e + 1; $f <= 45; $f++) {
                                yield ++$id => [$a, $b, $c, $d, $e, $f];
                            }
                        }
                    }
                }
            }
        }
    }

    /** 순서대로 적용했을 때 최초로 탈락하는 필터 단계이며 0은 최종 통과입니다. */
    public function rejection(array $n): int
    {
        $odd = count(array_filter($n, fn ($v) => $v % 2 === 1));
        if ($odd === 0 || $odd === 6) {
            return 1;
        }
        foreach ([3, 4, 5, 6, 7] as $m) {
            if (count(array_filter($n, fn ($v) => $v % $m === 0)) === 6) {
                return 2;
            }
        }
        for ($i = 0; $i < 4; $i++) {
            if ($n[$i + 1] === $n[$i] + 1 && $n[$i + 2] === $n[$i] + 2) {
                return 3;
            }
        }
        $range = $n[5] - $n[0];

        return $range < 25 || $range > 43 ? 4 : 0;
    }

    /** 고정 후보의 통계 특성을 한 번만 계산합니다. */
    public function row(int $id, array $n): array
    {
        return ['id' => $id, ...array_combine(['number1', 'number2', 'number3', 'number4', 'number5', 'number6'], $n), 'number_range' => $n[5] - $n[0], 'number_sum' => array_sum($n)];
    }

    /** 원본 당첨번호와 유효 범위 안의 동일 이동 조합만 제외 집합에 넣습니다. */
    public function exclusions(array $draws): array
    {
        $excluded = [];
        foreach ($draws as $draw) {
            $n = $this->numbers((array) $draw);
            for ($offset = 1 - $n[0]; $offset <= 45 - $n[5]; $offset++) {
                $excluded[implode(',', array_map(fn ($v) => $v + $offset, $n))] = true;
            }
        }

        return $excluded;
    }

    /** 통계 빈도 동점은 기존 draw와 같이 큰 값을 우선합니다. */
    public function statistics(array $draws): array
    {
        $diff = [];
        $sum = [];
        foreach ($draws as $draw) {
            $n = $this->numbers((array) $draw);
            $d = $n[5] - $n[0];
            $s = array_sum($n);
            $diff[$d] = ($diff[$d] ?? 0) + 1;
            $sum[$s] = ($sum[$s] ?? 0) + 1;
        }
        $top = function (array $counts, int $limit): array {
            $keys = array_keys($counts);
            usort($keys, fn ($a, $b) => ($counts[$b] <=> $counts[$a]) ?: ($b <=> $a));

            return array_slice($keys, 0, $limit);
        };

        return ['diffs' => $top($diff, 10), 'sums' => $top($sum, 20)];
    }

    /** 공용 번호 컬럼을 계산용 정수 배열로 변환합니다. */
    public function numbers(array $row): array
    {
        return array_map(fn ($i) => (int) $row['number'.$i], range(1,6));
    }
}
