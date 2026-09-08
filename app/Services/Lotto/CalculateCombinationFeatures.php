<?php

namespace App\Services\Lotto;

use RuntimeException;

/**
 * 오름차순 로또 6개 조합에서 회차에 따라 변하지 않는 특성을 계산한다.
 */
final class CalculateCombinationFeatures
{
    /**
     * @param  array<int, int>  $numbers
     * @return array<string, int>
     */
    public function calculate(array $numbers): array
    {
        $this->validate($numbers);

        $sectionCounts = [0, 0, 0, 0, 0];

        foreach ($numbers as $number) {
            /** 1~10, 11~20, 21~30, 31~40, 41~45의 다섯 구간으로 나눈다. */
            $section = min(intdiv($number - 1, 10), 4);
            $sectionCounts[$section]++;
        }

        return [
            'odd_count' => count(array_filter($numbers, fn (int $number): bool => $number % 2 === 1)),
            'number_sum' => array_sum($numbers),
            'low_count' => count(array_filter($numbers, fn (int $number): bool => $number <= 22)),
            'number_range' => $numbers[5] - $numbers[0],
            'max_consecutive_run' => $this->maxConsecutiveRun($numbers),
            'ac_value' => $this->acValue($numbers),
            'section1_count' => $sectionCounts[0],
            'section2_count' => $sectionCounts[1],
            'section3_count' => $sectionCounts[2],
            'section4_count' => $sectionCounts[3],
            'section5_count' => $sectionCounts[4],
        ];
    }

    /**
     * 같은 끝자리가 반복된 번호 수를 계산한다.
     *
     * 예를 들어 끝자리가 모두 다르면 0, 한 쌍이 같으면 1, 세 번호가 같으면 2다.
     *
     * @param  array<int, int>  $numbers
     */
    public function lastDigitRepeatCount(array $numbers): int
    {
        $this->validate($numbers);

        $lastDigits = array_map(fn (int $number): int => $number % 10, $numbers);

        return count($numbers) - count(array_unique($lastDigits));
    }

    /**
     * @param  array<int, int>  $numbers
     */
    private function validate(array $numbers): void
    {
        if (count($numbers) !== 6) {
            throw new RuntimeException('로또 조합은 6개 번호여야 합니다.');
        }

        foreach ($numbers as $index => $number) {
            if ($number < 1 || $number > 45) {
                throw new RuntimeException('로또 번호는 1부터 45 사이여야 합니다.');
            }

            if ($index > 0 && $numbers[$index - 1] >= $number) {
                throw new RuntimeException('로또 번호는 중복 없이 오름차순이어야 합니다.');
            }
        }
    }

    /**
     * @param  array<int, int>  $numbers
     */
    private function maxConsecutiveRun(array $numbers): int
    {
        $maximum = 1;
        $current = 1;

        for ($index = 1; $index < count($numbers); $index++) {
            $current = $numbers[$index] === $numbers[$index - 1] + 1
                ? $current + 1
                : 1;
            $maximum = max($maximum, $current);
        }

        return $maximum;
    }

    /**
     * 서로 다른 번호 차이 개수에서 선택 번호 수-1을 빼는 표준 AC값을 계산한다.
     *
     * @param  array<int, int>  $numbers
     */
    private function acValue(array $numbers): int
    {
        $differences = [];

        for ($left = 0; $left < count($numbers) - 1; $left++) {
            for ($right = $left + 1; $right < count($numbers); $right++) {
                $differences[$numbers[$right] - $numbers[$left]] = true;
            }
        }

        return count($differences) - (count($numbers) - 1);
    }
}
