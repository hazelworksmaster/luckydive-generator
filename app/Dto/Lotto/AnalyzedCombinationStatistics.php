<?php

namespace App\Dto\Lotto;

use JsonSerializable;

/**
 * 당첨번호 조합 통계 분석 결과를 커맨드 출력과 후속 모델에 전달한다.
 */
final readonly class AnalyzedCombinationStatistics implements JsonSerializable
{
    /**
     * @param  array<string, array<int, array{value: int|string, label: string, count: int, percentage: float}>>  $distributions
     * @param  array<int, array{number: int, count: int, percentage: float, last_seen_round: int|null, absence_rounds: int|null, current_streak: int}>  $numberStatistics
     */
    public function __construct(
        public int $requestedToRound,
        public int $firstRound,
        public int $lastRound,
        public int $drawCount,
        public ?int $window,
        public array $distributions,
        public array $numberStatistics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'requested_to_round' => $this->requestedToRound,
            'first_round' => $this->firstRound,
            'last_round' => $this->lastRound,
            'draw_count' => $this->drawCount,
            'window' => $this->window,
            'distributions' => $this->distributions,
            'number_statistics' => $this->numberStatistics,
        ];
    }
}
