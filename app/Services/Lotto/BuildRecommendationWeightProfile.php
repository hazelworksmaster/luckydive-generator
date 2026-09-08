<?php

namespace App\Services\Lotto;

use App\Dto\Lotto\RecommendationWeightProfile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 전체 조합 분포와 장·단기 당첨 분포를 혼합해 가중 추천 모델을 구성한다.
 */
final class BuildRecommendationWeightProfile
{
    private const FEATURES = [
        'odd_even',
        'sum',
        'low_high',
        'number_range',
        'max_consecutive_run',
        'ac_value',
        'previous_overlap',
        'last_digit_repeat',
        'cooccurrence',
    ];

    public function __construct(
        private readonly AnalyzeCombinationStatistics $analyzeCombinationStatistics,
    ) {}

    public function build(?int $toRound = null): RecommendationWeightProfile
    {
        $version = (string) config('lotto.recommendation_model.version', 'weighted_v2');
        $recentWindow = (int) config('lotto.recommendation_model.recent_window', 200);
        $blend = (array) config('lotto.recommendation_model.distribution_blend', []);
        $featureWeights = (array) config('lotto.recommendation_model.feature_weights', []);
        $minimumLift = (float) config('lotto.recommendation_model.minimum_lift', 0.75);
        $maximumLift = (float) config('lotto.recommendation_model.maximum_lift', 1.25);
        $this->validateConfiguration($blend, $featureWeights, $minimumLift, $maximumLift);

        $allDraws = $this->analyzeCombinationStatistics->analyze($toRound, null);
        $recentDraws = $this->analyzeCombinationStatistics->analyze($allDraws->requestedToRound, $recentWindow);
        $baseline = $this->baselineDistributions();
        $distributions = [];

        foreach (self::FEATURES as $feature) {
            $allPercentages = $this->percentageMap($feature, $allDraws->distributions[$feature]);
            $recentPercentages = $this->percentageMap($feature, $recentDraws->distributions[$feature]);
            $distributions[$feature] = $this->profileRows(
                baselineRows: $baseline[$feature],
                allPercentages: $allPercentages,
                recentPercentages: $recentPercentages,
                blend: $blend,
                minimumLift: $minimumLift,
                maximumLift: $maximumLift,
            );
        }

        return new RecommendationWeightProfile(
            version: $version,
            toRound: $allDraws->requestedToRound,
            recentWindow: $recentWindow,
            distributionBlend: $blend,
            featureWeights: $featureWeights,
            minimumLift: $minimumLift,
            maximumLift: $maximumLift,
            distributions: $distributions,
        );
    }

    /**
     * @param  array<string, float|int>  $blend
     * @param  array<string, float|int>  $featureWeights
     */
    private function validateConfiguration(array $blend, array $featureWeights, float $minimumLift, float $maximumLift): void
    {
        foreach (['baseline', 'all_draws', 'recent_draws'] as $key) {
            if (! array_key_exists($key, $blend)) {
                throw new RuntimeException('추천 모델 분포 혼합 설정에 '.$key.' 값이 없습니다.');
            }
        }

        if (abs(array_sum($blend) - 1.0) > 0.000001) {
            throw new RuntimeException('추천 모델 분포 혼합 비중의 합은 1이어야 합니다.');
        }

        foreach (self::FEATURES as $feature) {
            if (! array_key_exists($feature, $featureWeights)) {
                throw new RuntimeException('추천 모델 특성 가중치에 '.$feature.' 값이 없습니다.');
            }
        }

        if (abs(array_sum($featureWeights) - 1.0) > 0.000001) {
            throw new RuntimeException('추천 모델 특성 가중치의 합은 1이어야 합니다.');
        }

        if ($minimumLift <= 0 || $maximumLift < $minimumLift) {
            throw new RuntimeException('추천 모델 보정 배율 범위가 올바르지 않습니다.');
        }
    }

    /**
     * @return array<string, array<int, array{value: int|string, label: string, percentage: float}>>
     */
    private function baselineDistributions(): array
    {
        $total = (int) DB::table('lotto_combinations')->count();

        if ($total < 1) {
            throw new RuntimeException('lotto_combinations 테이블에 전체 조합이 없습니다.');
        }

        return [
            'odd_even' => $this->databaseDistribution('odd_even', 'odd_count', $total),
            'sum' => $this->sumDistribution($total),
            'low_high' => $this->databaseDistribution('low_high', 'low_count', $total),
            'number_range' => $this->databaseDistribution('number_range', 'number_range', $total),
            'max_consecutive_run' => $this->databaseDistribution('max_consecutive_run', 'max_consecutive_run', $total),
            'ac_value' => $this->databaseDistribution('ac_value', 'ac_value', $total),
            'previous_overlap' => $this->overlapDistribution(),
            'last_digit_repeat' => $this->lastDigitRepeatDistribution(),
            'cooccurrence' => $this->cooccurrenceDistribution(),
        ];
    }

    /**
     * 끝자리별 번호 개수를 이용해 6개 조합의 끝자리 중복 분포를 정확히 계산한다.
     *
     * @return array<int, array{value: int, label: string, percentage: float}>
     */
    private function lastDigitRepeatDistribution(): array
    {
        /** 1~45에서 끝자리 1~5는 5개, 나머지 끝자리는 4개씩 존재한다. */
        $bucketSizes = [4, 5, 5, 5, 5, 5, 4, 4, 4, 4];
        $states = [0 => [0 => 1]];

        foreach ($bucketSizes as $bucketSize) {
            $next = [];

            foreach ($states as $selected => $usedGroups) {
                foreach ($usedGroups as $groupCount => $ways) {
                    for ($take = 0; $take <= min($bucketSize, 6 - $selected); $take++) {
                        $nextSelected = $selected + $take;
                        $nextGroupCount = $groupCount + ($take > 0 ? 1 : 0);
                        $next[$nextSelected][$nextGroupCount] = ($next[$nextSelected][$nextGroupCount] ?? 0)
                            + ($ways * $this->combination($bucketSize, $take));
                    }
                }
            }

            $states = $next;
        }

        $total = $this->combination(45, 6);
        $counts = [];

        foreach ($states[6] ?? [] as $usedGroups => $ways) {
            $counts[6 - $usedGroups] = ($counts[6 - $usedGroups] ?? 0) + $ways;
        }

        ksort($counts);

        return array_map(fn (int $count, int $value): array => [
            'value' => $value,
            'label' => $this->profileLabel('last_digit_repeat', $value),
            'percentage' => ($count / $total) * 100,
        ], array_values($counts), array_keys($counts));
    }

    /**
     * 임의의 두 번호가 한 조합에 함께 포함될 기본 확률은 모든 번호 쌍에서 동일하다.
     *
     * @return array<int, array{value: string, label: string, percentage: float}>
     */
    private function cooccurrenceDistribution(): array
    {
        $percentage = ($this->combination(43, 4) / $this->combination(45, 6)) * 100;
        $rows = [];

        for ($left = 1; $left <= 44; $left++) {
            for ($right = $left + 1; $right <= 45; $right++) {
                $value = $left.':'.$right;
                $rows[] = [
                    'value' => $value,
                    'label' => $this->profileLabel('cooccurrence', $value),
                    'percentage' => $percentage,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array{value: int, label: string, percentage: float}>
     */
    private function databaseDistribution(string $feature, string $column, int $total): array
    {
        $counts = [];
        $rows = DB::table('lotto_combinations')
            ->selectRaw($column.' AS value, COUNT(*) AS aggregate')
            ->groupBy($column)
            ->orderBy($column)
            ->get();

        foreach ($rows as $row) {
            $value = $this->normalizeValue($feature, (int) $row->value);
            $counts[$value] = ($counts[$value] ?? 0) + (int) $row->aggregate;
        }

        ksort($counts);

        return array_map(fn (int $count, int $value): array => [
            'value' => $value,
            'label' => $this->profileLabel($feature, $value),
            'percentage' => ($count / $total) * 100,
        ], array_values($counts), array_keys($counts));
    }

    /**
     * @return array<int, array{value: int, label: string, percentage: float}>
     */
    private function sumDistribution(int $total): array
    {
        return DB::table('lotto_combinations')
            ->selectRaw('(21 + FLOOR((number_sum - 21) / 20) * 20) AS value, COUNT(*) AS aggregate')
            ->groupBy('value')
            ->orderBy('value')
            ->get()
            ->map(fn (object $row): array => [
                'value' => (int) $row->value,
                'label' => $row->value.'~'.min((int) $row->value + 19, 255),
                'percentage' => ((int) $row->aggregate / $total) * 100,
            ])
            ->all();
    }

    /**
     * 직전 당첨번호 6개와 무작위 조합의 중복 개수는 조합식으로 정확히 계산한다.
     *
     * @return array<int, array{value: int, label: string, percentage: float}>
     */
    private function overlapDistribution(): array
    {
        $total = $this->combination(45, 6);
        $counts = [];

        for ($overlap = 0; $overlap <= 6; $overlap++) {
            $count = $this->combination(6, $overlap) * $this->combination(39, 6 - $overlap);
            $value = $this->normalizeValue('previous_overlap', $overlap);
            $counts[$value] = ($counts[$value] ?? 0) + $count;
        }

        return array_map(fn (int $count, int $value): array => [
            'value' => $value,
            'label' => $this->profileLabel('previous_overlap', $value),
            'percentage' => ($count / $total) * 100,
        ], array_values($counts), array_keys($counts));
    }

    private function combination(int $n, int $r): int
    {
        if ($r < 0 || $r > $n) {
            return 0;
        }

        $r = min($r, $n - $r);
        $result = 1;

        for ($index = 1; $index <= $r; $index++) {
            $result = intdiv($result * ($n - $r + $index), $index);
        }

        return $result;
    }

    /**
     * @param  array<int, array{value: int|string, label: string, count: int, percentage: float}>  $rows
     * @return array<int|string, float>
     */
    private function percentageMap(string $feature, array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $value = $this->normalizeValue($feature, $row['value']);
            $map[$value] = ($map[$value] ?? 0.0) + $row['percentage'];
        }

        return $map;
    }

    private function normalizeValue(string $feature, int|string $value): int|string
    {
        if ($feature === 'cooccurrence') {
            return (string) $value;
        }

        $value = (int) $value;

        return match ($feature) {
            'number_range' => 5 + (intdiv($value - 5, 10) * 10),
            'max_consecutive_run' => min($value, 4),
            'ac_value' => match (true) {
                $value <= 4 => 4,
                $value <= 6 => 6,
                $value <= 8 => 8,
                default => 10,
            },
            'previous_overlap' => min($value, 4),
            default => $value,
        };
    }

    private function profileLabel(string $feature, int|string $value): string
    {
        return match ($feature) {
            'odd_even', 'low_high' => $value.':'.(6 - (int) $value),
            'number_range' => $value.'~'.min((int) $value + 9, 44),
            'max_consecutive_run' => $value === 4 ? '4개 이상' : $value.'개',
            'ac_value' => match ($value) {
                4 => '0~4',
                6 => '5~6',
                8 => '7~8',
                default => '9~10',
            },
            'previous_overlap' => $value === 4 ? '4개 이상' : $value.'개',
            'last_digit_repeat' => $value.'개',
            'cooccurrence' => str_replace(':', '·', (string) $value),
            default => (string) $value,
        };
    }

    /**
     * @param  array<int, array{value: int|string, label: string, percentage: float}>  $baselineRows
     * @param  array<int|string, float>  $allPercentages
     * @param  array<int|string, float>  $recentPercentages
     * @param  array<string, float|int>  $blend
     * @return array<int, array{value: int|string, label: string, baseline_percentage: float, all_draws_percentage: float, recent_draws_percentage: float, target_percentage: float, lift: float}>
     */
    private function profileRows(
        array $baselineRows,
        array $allPercentages,
        array $recentPercentages,
        array $blend,
        float $minimumLift,
        float $maximumLift,
    ): array {
        return array_map(function (array $baseline) use ($allPercentages, $recentPercentages, $blend, $minimumLift, $maximumLift): array {
            $value = $baseline['value'];
            $allPercentage = $allPercentages[$value] ?? 0.0;
            $recentPercentage = $recentPercentages[$value] ?? 0.0;
            $targetPercentage = ($baseline['percentage'] * (float) $blend['baseline'])
                + ($allPercentage * (float) $blend['all_draws'])
                + ($recentPercentage * (float) $blend['recent_draws']);
            $rawLift = $baseline['percentage'] > 0 ? $targetPercentage / $baseline['percentage'] : 1.0;

            return [
                'value' => $value,
                'label' => $baseline['label'],
                'baseline_percentage' => round($baseline['percentage'], 4),
                'all_draws_percentage' => round($allPercentage, 4),
                'recent_draws_percentage' => round($recentPercentage, 4),
                'target_percentage' => round($targetPercentage, 4),
                'lift' => round(max($minimumLift, min($maximumLift, $rawLift)), 6),
            ];
        }, $baselineRows);
    }
}
