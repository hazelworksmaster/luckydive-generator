<?php

namespace App\Services\Lotto;

use App\Dto\Lotto\AnalyzedCombinationStatistics;
use App\Models\Lotto\LottoDraw;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * 저장된 당첨번호를 조합 특성으로 변환해 빈도 분포와 번호별 상태를 계산한다.
 */
final class AnalyzeCombinationStatistics
{
    public function __construct(
        private readonly CalculateCombinationFeatures $calculateCombinationFeatures,
    ) {}

    public function analyze(?int $toRound, ?int $window): AnalyzedCombinationStatistics
    {
        $latestRound = (int) (LottoDraw::query()->max('round') ?? 0);

        if ($latestRound < 1) {
            throw new RuntimeException('winning_numbers 테이블에 당첨번호가 없습니다.');
        }

        $requestedToRound = $toRound ?? $latestRound;

        if ($requestedToRound < 1) {
            throw new RuntimeException('to-round 값은 1 이상이어야 합니다.');
        }

        if ($requestedToRound > $latestRound) {
            throw new RuntimeException('to-round 값은 저장된 최신 회차 '.$latestRound.'보다 클 수 없습니다.');
        }

        if ($window !== null && $window < 1) {
            throw new RuntimeException('window 값은 1 이상이어야 합니다.');
        }

        $query = LottoDraw::query()
            ->where('round', '<=', $requestedToRound)
            ->orderByDesc('round');

        if ($window !== null) {
            $query->limit($window);
        }

        /** @var Collection<int, LottoDraw> $draws */
        $draws = $query->get()->sortBy('round')->values();

        if ($draws->isEmpty()) {
            throw new RuntimeException('분석할 당첨번호가 없습니다.');
        }

        $firstRound = (int) $draws->first()->round;
        $previousDraw = LottoDraw::query()
            ->where('round', '<', $firstRound)
            ->orderByDesc('round')
            ->first();
        $counts = $this->emptyDistributionCounts();
        $numberCounts = array_fill(1, 45, 0);
        $overlapSampleCount = 0;

        foreach ($draws as $draw) {
            $numbers = $this->numbers($draw);
            $features = $this->calculateCombinationFeatures->calculate($numbers);

            $this->increment($counts['odd_even'], $features['odd_count']);
            $this->increment($counts['sum'], $this->sumRangeStart($features['number_sum']));
            $this->increment($counts['low_high'], $features['low_count']);
            $this->increment($counts['number_range'], $features['number_range']);
            $this->increment($counts['max_consecutive_run'], $features['max_consecutive_run']);
            $this->increment($counts['ac_value'], $features['ac_value']);
            $this->increment($counts['section_pattern'], $this->sectionPattern($features));
            $this->increment($counts['last_digit_repeat'], $this->calculateCombinationFeatures->lastDigitRepeatCount($numbers));

            foreach ($numbers as $leftIndex => $left) {
                foreach (array_slice($numbers, $leftIndex + 1) as $right) {
                    $this->increment($counts['cooccurrence'], $left.':'.$right);
                }
            }

            foreach ($numbers as $number) {
                $numberCounts[$number]++;
            }

            if ($previousDraw !== null) {
                $overlapCount = count(array_intersect($numbers, $this->numbers($previousDraw)));
                $this->increment($counts['previous_overlap'], $overlapCount);
                $overlapSampleCount++;
            }

            $previousDraw = $draw;
        }

        $allDraws = LottoDraw::query()
            ->where('round', '<=', $requestedToRound)
            ->orderBy('round')
            ->get();

        return new AnalyzedCombinationStatistics(
            requestedToRound: $requestedToRound,
            firstRound: $firstRound,
            lastRound: (int) $draws->last()->round,
            drawCount: $draws->count(),
            window: $window,
            distributions: $this->distributions($counts, $draws->count(), $overlapSampleCount),
            numberStatistics: $this->numberStatistics($numberCounts, $draws->count(), $allDraws, $requestedToRound),
        );
    }

    /**
     * @return array<string, array<int|string, int>>
     */
    private function emptyDistributionCounts(): array
    {
        return [
            'odd_even' => [],
            'sum' => [],
            'low_high' => [],
            'number_range' => [],
            'max_consecutive_run' => [],
            'ac_value' => [],
            'section_pattern' => [],
            'previous_overlap' => [],
            'last_digit_repeat' => [],
            'cooccurrence' => [],
        ];
    }

    /**
     * @param  array<int|string, int>  $counts
     */
    private function increment(array &$counts, int|string $value): void
    {
        $counts[$value] = ($counts[$value] ?? 0) + 1;
    }

    private function sumRangeStart(int $sum): int
    {
        /** 가능한 최소 합계 21을 기준으로 20점 단위 구간을 사용한다. */
        return 21 + (intdiv($sum - 21, 20) * 20);
    }

    /**
     * @param  array<string, int>  $features
     */
    private function sectionPattern(array $features): string
    {
        return implode(':', [
            $features['section1_count'],
            $features['section2_count'],
            $features['section3_count'],
            $features['section4_count'],
            $features['section5_count'],
        ]);
    }

    /**
     * @param  array<string, array<int|string, int>>  $counts
     * @return array<string, array<int, array{value: int|string, label: string, count: int, percentage: float}>>
     */
    private function distributions(array $counts, int $drawCount, int $overlapSampleCount): array
    {
        $distributions = [];

        foreach ($counts as $name => $values) {
            $denominator = $name === 'previous_overlap' ? $overlapSampleCount : $drawCount;
            $sortByFrequency = in_array($name, ['section_pattern', 'cooccurrence'], true);
            $distributions[$name] = $this->distributionRows(
                name: $name,
                counts: $values,
                denominator: $denominator,
                sortByFrequency: $sortByFrequency,
            );
        }

        return $distributions;
    }

    /**
     * @param  array<int|string, int>  $counts
     * @return array<int, array{value: int|string, label: string, count: int, percentage: float}>
     */
    private function distributionRows(string $name, array $counts, int $denominator, bool $sortByFrequency): array
    {
        $rows = [];

        foreach ($counts as $value => $count) {
            $normalizedValue = is_numeric($value) ? (int) $value : (string) $value;
            $rows[] = [
                'value' => $normalizedValue,
                'label' => $this->distributionLabel($name, $normalizedValue),
                'count' => $count,
                'percentage' => $denominator > 0 ? round(($count / $denominator) * 100, 2) : 0.0,
            ];
        }

        usort($rows, function (array $left, array $right) use ($sortByFrequency): int {
            if ($sortByFrequency && $left['count'] !== $right['count']) {
                return $right['count'] <=> $left['count'];
            }

            return $left['value'] <=> $right['value'];
        });

        return $rows;
    }

    private function distributionLabel(string $name, int|string $value): string
    {
        return match ($name) {
            'odd_even', 'low_high' => $value.':'.(6 - (int) $value),
            'sum' => $value.'~'.min((int) $value + 19, 255),
            'number_range' => (string) $value,
            'max_consecutive_run' => $value.'개',
            'ac_value' => (string) $value,
            'section_pattern' => (string) $value,
            'previous_overlap' => $value.'개',
            'last_digit_repeat' => $value.'개',
            'cooccurrence' => str_replace(':', '·', (string) $value),
            default => (string) $value,
        };
    }

    /**
     * @param  array<int, int>  $numberCounts
     * @param  Collection<int, LottoDraw>  $allDraws
     * @return array<int, array{number: int, count: int, percentage: float, last_seen_round: int|null, absence_rounds: int|null, current_streak: int}>
     */
    private function numberStatistics(array $numberCounts, int $drawCount, Collection $allDraws, int $toRound): array
    {
        $lastSeenRounds = array_fill(1, 45, null);

        foreach ($allDraws as $draw) {
            foreach ($this->numbers($draw) as $number) {
                $lastSeenRounds[$number] = (int) $draw->round;
            }
        }

        $statistics = [];

        for ($number = 1; $number <= 45; $number++) {
            $lastSeenRound = $lastSeenRounds[$number];
            $statistics[] = [
                'number' => $number,
                'count' => $numberCounts[$number],
                'percentage' => round(($numberCounts[$number] / $drawCount) * 100, 2),
                'last_seen_round' => $lastSeenRound,
                'absence_rounds' => $lastSeenRound === null ? null : $toRound - $lastSeenRound,
                'current_streak' => $this->currentAppearanceStreak($number, $allDraws),
            ];
        }

        return $statistics;
    }

    /**
     * @param  Collection<int, LottoDraw>  $draws
     */
    private function currentAppearanceStreak(int $number, Collection $draws): int
    {
        $streak = 0;

        foreach ($draws->reverse() as $draw) {
            if (! in_array($number, $this->numbers($draw), true)) {
                break;
            }

            $streak++;
        }

        return $streak;
    }

    /**
     * @return array<int, int>
     */
    private function numbers(LottoDraw $draw): array
    {
        return [
            $draw->number1,
            $draw->number2,
            $draw->number3,
            $draw->number4,
            $draw->number5,
            $draw->number6,
        ];
    }
}
