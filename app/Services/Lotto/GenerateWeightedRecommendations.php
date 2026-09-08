<?php

namespace App\Services\Lotto;

use App\Dto\Lotto\GeneratedWeightedRecommendations;
use App\Dto\Lotto\RecommendationWeightProfile;
use App\Models\Lotto\LottoDraw;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * 전체 조합의 균등 후보 풀에서 모델 가중치와 게임 간 다양성을 적용해 추천번호를 추출한다.
 */
final class GenerateWeightedRecommendations
{
    private const MAXIMUM_POOL_SIZE = 500000;

    private const MAXIMUM_GAME_COUNT = 50000;

    private const QUERY_CHUNK_SIZE = 2000;

    public function __construct(
        private readonly BuildRecommendationWeightProfile $buildRecommendationWeightProfile,
        private readonly ScoreRecommendationCombination $scoreRecommendationCombination,
    ) {}

    public function generate(
        ?int $toRound,
        int $count,
        int $poolSize,
        int $maximumGameOverlap,
        int $seed,
        ?callable $onProgress = null,
        ?RecommendationWeightProfile $profile = null,
        bool $includeComponents = true,
    ): GeneratedWeightedRecommendations {
        $this->validateOptions($count, $poolSize, $maximumGameOverlap, $seed);

        $profile ??= $this->buildRecommendationWeightProfile->build($toRound);
        $previousWinningNumbers = $this->winningNumbers($profile->toRound);
        $historicalWinningKeys = $this->historicalWinningKeys($profile->toRound);
        $combinationCount = (int) DB::table('lotto_combinations')->count();
        $maximumCombinationId = (int) (DB::table('lotto_combinations')->max('id') ?? 0);

        if ($combinationCount < 1 || $combinationCount !== $maximumCombinationId) {
            throw new RuntimeException('lotto_combinations가 1번부터 연속적인 전체 조합 상태가 아닙니다.');
        }

        $eligibleCombinationCount = $combinationCount - count($historicalWinningKeys);
        $actualPoolSize = min($poolSize, $eligibleCombinationCount);

        if ($actualPoolSize < $count) {
            throw new RuntimeException('후보 풀은 추천 게임 수보다 크거나 같아야 합니다.');
        }

        $randomizer = new Randomizer(new Mt19937($seed));
        /** 표본에 과거 1등 조합이 모두 들어와도 요청한 적격 후보 수를 확보할 만큼 여유 있게 뽑는다. */
        $sampleSize = min($combinationCount, $actualPoolSize + count($historicalWinningKeys));
        $candidateIds = $this->randomCandidateIds($randomizer, $maximumCombinationId, $sampleSize);
        $rankedCandidates = [];
        $processedCount = 0;

        foreach (array_chunk($candidateIds, self::QUERY_CHUNK_SIZE) as $idChunk) {
            $rows = DB::table('lotto_combinations')
                ->whereIn('id', $idChunk)
                ->get()
                ->keyBy('id');

            /** DB 반환 순서가 아니라 난수로 뽑힌 ID 순서를 유지해 일부 번호 구간에 치우치지 않게 한다. */
            foreach ($idChunk as $candidateId) {
                $row = $rows->get($candidateId);

                if ($row === null) {
                    continue;
                }

                $numbers = $this->numbers($row);

                if (isset($historicalWinningKeys[$this->combinationKey($numbers)])) {
                    continue;
                }

                if (count($rankedCandidates) === $actualPoolSize) {
                    break 2;
                }

                $score = $this->scoreRecommendationCombination->score(
                    numbers: $numbers,
                    previousWinningNumbers: $previousWinningNumbers,
                    profile: $profile,
                );
                $uniform = $randomizer->getInt(1, 2147483646) / 2147483647;
                $rankedCandidates[] = [
                    'combination_id' => (int) $row->id,
                    'numbers' => $numbers,
                    'sampling_weight' => $score->samplingWeight,
                    /** 가중치가 큰 후보일수록 0에 가까운 큰 키를 받아 상위에 올 가능성이 높다. */
                    'random_key' => log($uniform) / $score->samplingWeight,
                ];
            }

            $processedCount = count($rankedCandidates);

            if ($onProgress !== null) {
                $onProgress($processedCount, $actualPoolSize);
            }
        }

        if (count($rankedCandidates) !== $actualPoolSize) {
            throw new RuntimeException('요청한 후보 풀을 lotto_combinations에서 모두 읽지 못했습니다.');
        }

        usort($rankedCandidates, fn (array $left, array $right): int => ($right['random_key'] <=> $left['random_key']) ?: ($left['combination_id'] <=> $right['combination_id']));
        $selected = $this->selectDiverseGames($rankedCandidates, $count, $maximumGameOverlap);
        $games = [];

        foreach ($selected as $index => $candidate) {
            unset($candidate['random_key']);
            $game = ['sequence' => $index + 1] + $candidate;

            if ($includeComponents) {
                $score = $this->scoreRecommendationCombination->score(
                    numbers: $candidate['numbers'],
                    previousWinningNumbers: $previousWinningNumbers,
                    profile: $profile,
                );
                $game['components'] = $score->components;
            }

            $games[] = $game;
        }

        return new GeneratedWeightedRecommendations(
            modelVersion: $profile->version,
            statisticsToRound: $profile->toRound,
            targetRound: $profile->toRound + 1,
            seed: $seed,
            candidatePoolSize: $actualPoolSize,
            historicalWinningCombinationCount: count($historicalWinningKeys),
            maximumGameOverlap: $maximumGameOverlap,
            games: $games,
        );
    }

    private function validateOptions(int $count, int $poolSize, int $maximumGameOverlap, int $seed): void
    {
        if ($count < 1 || $count > self::MAXIMUM_GAME_COUNT) {
            throw new RuntimeException('count 값은 1부터 '.number_format(self::MAXIMUM_GAME_COUNT).' 사이여야 합니다.');
        }

        if ($poolSize < 1 || $poolSize > self::MAXIMUM_POOL_SIZE) {
            throw new RuntimeException('pool-size 값은 1부터 '.number_format(self::MAXIMUM_POOL_SIZE).' 사이여야 합니다.');
        }

        if ($maximumGameOverlap < 0 || $maximumGameOverlap > 5) {
            throw new RuntimeException('max-overlap 값은 0부터 5 사이여야 합니다.');
        }

        if ($seed < 1) {
            throw new RuntimeException('seed 값은 1 이상이어야 합니다.');
        }
    }

    /**
     * @return array<int, int>
     */
    private function randomCandidateIds(Randomizer $randomizer, int $maximumId, int $poolSize): array
    {
        $ids = [];

        while (count($ids) < $poolSize) {
            $id = $randomizer->getInt(1, $maximumId);
            $ids[$id] = true;
        }

        return array_keys($ids);
    }

    /**
     * @return array<int, int>
     */
    private function winningNumbers(int $round): array
    {
        $draw = LottoDraw::query()->find($round);

        if ($draw === null) {
            throw new RuntimeException($round.'회 당첨번호가 없습니다.');
        }

        return [
            $draw->number1,
            $draw->number2,
            $draw->number3,
            $draw->number4,
            $draw->number5,
            $draw->number6,
        ];
    }

    /**
     * @param  array<int, array{combination_id: int, numbers: array<int, int>, sampling_weight: float, random_key: float}>  $rankedCandidates
     * @return array<int, array{combination_id: int, numbers: array<int, int>, sampling_weight: float, random_key: float}>
     */
    private function selectDiverseGames(array $rankedCandidates, int $count, int $maximumGameOverlap): array
    {
        /** 서로 다른 6개 조합은 최대 5개까지만 겹치므로 제한이 5면 정렬 상위 후보를 바로 사용한다. */
        if ($maximumGameOverlap === 5) {
            return array_slice($rankedCandidates, 0, $count);
        }

        $selected = [];

        foreach ($rankedCandidates as $candidate) {
            $hasExcessiveOverlap = false;

            foreach ($selected as $selectedCandidate) {
                if (count(array_intersect($candidate['numbers'], $selectedCandidate['numbers'])) > $maximumGameOverlap) {
                    $hasExcessiveOverlap = true;
                    break;
                }
            }

            if ($hasExcessiveOverlap) {
                continue;
            }

            $selected[] = $candidate;

            if (count($selected) === $count) {
                return $selected;
            }
        }

        throw new RuntimeException('게임 간 중복 제한을 만족하는 추천번호를 충분히 추출하지 못했습니다.');
    }

    /**
     * @return array<int, int>
     */
    private function numbers(object $row): array
    {
        return [
            (int) $row->number1,
            (int) $row->number2,
            (int) $row->number3,
            (int) $row->number4,
            (int) $row->number5,
            (int) $row->number6,
        ];
    }

    /**
     * 기준 회차까지 실제 1등이었던 정규번호 6개 조합을 조회한다. 보너스 번호는 비교하지 않는다.
     *
     * @return array<string, true>
     */
    private function historicalWinningKeys(int $toRound): array
    {
        $keys = [];

        LottoDraw::query()
            ->where('round', '<=', $toRound)
            ->orderBy('round')
            ->get(['number1', 'number2', 'number3', 'number4', 'number5', 'number6'])
            ->each(function (LottoDraw $draw) use (&$keys): void {
                $keys[$this->combinationKey([
                    $draw->number1,
                    $draw->number2,
                    $draw->number3,
                    $draw->number4,
                    $draw->number5,
                    $draw->number6,
                ])] = true;
            });

        return $keys;
    }

    /**
     * @param  array<int, int>  $numbers
     */
    private function combinationKey(array $numbers): string
    {
        sort($numbers, SORT_NUMERIC);

        return implode('-', $numbers);
    }
}
