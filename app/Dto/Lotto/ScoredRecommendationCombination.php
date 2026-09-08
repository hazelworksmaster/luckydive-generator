<?php

namespace App\Dto\Lotto;

/**
 * 한 조합의 모델 특성과 최종 가중 추출 점수를 전달한다.
 */
final readonly class ScoredRecommendationCombination
{
    /**
     * @param  array<int, int>  $numbers
     * @param  array<string, array{value: int|string, lift: float, feature_weight: float}>  $components
     */
    public function __construct(
        public array $numbers,
        public array $components,
        public float $samplingWeight,
    ) {}
}
