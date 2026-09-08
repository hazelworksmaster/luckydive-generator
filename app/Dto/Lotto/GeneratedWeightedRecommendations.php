<?php

namespace App\Dto\Lotto;

use JsonSerializable;

/**
 * 가중 무작위로 추출한 회차별 추천 게임과 재현 정보를 전달한다.
 */
final readonly class GeneratedWeightedRecommendations implements JsonSerializable
{
    /**
     * @param  array<int, array{sequence: int, combination_id: int, numbers: array<int, int>, sampling_weight: float, components: array<string, array{value: int, lift: float, feature_weight: float}>}>  $games
     */
    public function __construct(
        public string $modelVersion,
        public int $statisticsToRound,
        public int $targetRound,
        public int $seed,
        public int $candidatePoolSize,
        public int $historicalWinningCombinationCount,
        public int $maximumGameOverlap,
        public array $games,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'model_version' => $this->modelVersion,
            'statistics_to_round' => $this->statisticsToRound,
            'target_round' => $this->targetRound,
            'seed' => $this->seed,
            'candidate_pool_size' => $this->candidatePoolSize,
            'historical_winning_combination_count' => $this->historicalWinningCombinationCount,
            'maximum_game_overlap' => $this->maximumGameOverlap,
            'games' => $this->games,
        ];
    }
}
