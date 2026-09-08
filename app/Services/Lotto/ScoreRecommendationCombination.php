<?php

namespace App\Services\Lotto;

use App\Dto\Lotto\RecommendationWeightProfile;
use App\Dto\Lotto\ScoredRecommendationCombination;

/**
 * 조합 특성과 직전 회차 중복 수를 모델 보정 배율로 변환한다.
 */
final class ScoreRecommendationCombination
{
    public function __construct(
        private readonly CalculateCombinationFeatures $calculateCombinationFeatures,
    ) {}

    /**
     * @param  array<int, int>  $numbers
     * @param  array<int, int>  $previousWinningNumbers
     */
    public function score(
        array $numbers,
        array $previousWinningNumbers,
        RecommendationWeightProfile $profile,
    ): ScoredRecommendationCombination {
        $features = $this->calculateCombinationFeatures->calculate($numbers);
        $values = [
            'odd_even' => $features['odd_count'],
            'sum' => 21 + (intdiv($features['number_sum'] - 21, 20) * 20),
            'low_high' => $features['low_count'],
            'number_range' => 5 + (intdiv($features['number_range'] - 5, 10) * 10),
            'max_consecutive_run' => min($features['max_consecutive_run'], 4),
            'ac_value' => match (true) {
                $features['ac_value'] <= 4 => 4,
                $features['ac_value'] <= 6 => 6,
                $features['ac_value'] <= 8 => 8,
                default => 10,
            },
            'previous_overlap' => min(count(array_intersect($numbers, $previousWinningNumbers)), 4),
            'last_digit_repeat' => $this->calculateCombinationFeatures->lastDigitRepeatCount($numbers),
            'cooccurrence' => '15개 번호쌍',
        ];
        $components = [];
        $weightedLogLift = 0.0;

        foreach ($profile->featureWeights as $feature => $featureWeight) {
            $lift = $feature === 'cooccurrence'
                ? $this->cooccurrenceLift($numbers, $profile)
                : $profile->liftFor($feature, $values[$feature]);
            $components[$feature] = [
                'value' => $values[$feature],
                'lift' => $lift,
                'feature_weight' => (float) $featureWeight,
            ];
            /** 특성 간 배율을 기하 평균해 어느 한 특성이 최종값을 지배하지 않게 한다. */
            $weightedLogLift += (float) $featureWeight * log($lift);
        }

        return new ScoredRecommendationCombination(
            numbers: $numbers,
            components: $components,
            samplingWeight: round(exp($weightedLogLift), 6),
        );
    }

    /**
     * 조합에 포함된 15개 번호 쌍의 배율을 기하 평균해 한 쌍의 과도한 영향을 제한한다.
     *
     * @param  array<int, int>  $numbers
     */
    private function cooccurrenceLift(array $numbers, RecommendationWeightProfile $profile): float
    {
        $logLift = 0.0;
        $pairCount = 0;

        foreach ($numbers as $leftIndex => $left) {
            foreach (array_slice($numbers, $leftIndex + 1) as $right) {
                $logLift += log($profile->liftFor('cooccurrence', $left.':'.$right));
                $pairCount++;
            }
        }

        return $pairCount > 0 ? exp($logLift / $pairCount) : 1.0;
    }
}
