<?php

namespace App\Dto\Lotto;

/**
 * 가중 추천 모델의 기준 분포와 특성별 보정 배율을 보관한다.
 */
final readonly class RecommendationWeightProfile
{
    /**
     * @param  array<string, float>  $distributionBlend
     * @param  array<string, float>  $featureWeights
     * @param  array<string, array<int, array{value: int|string, label: string, baseline_percentage: float, all_draws_percentage: float, recent_draws_percentage: float, target_percentage: float, lift: float}>>  $distributions
     */
    public function __construct(
        public string $version,
        public int $toRound,
        public int $recentWindow,
        public array $distributionBlend,
        public array $featureWeights,
        public float $minimumLift,
        public float $maximumLift,
        public array $distributions,
    ) {}

    public function liftFor(string $feature, int|string $value): float
    {
        foreach ($this->distributions[$feature] ?? [] as $row) {
            if ($row['value'] === $value) {
                return $row['lift'];
            }
        }

        return 1.0;
    }
}
