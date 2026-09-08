<?php

namespace App\Services\Weighted;

use App\Services\Lotto\BuildRecommendationWeightProfile;
use App\Services\Lotto\GenerateWeightedRecommendations;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PrepareWeighted
{
    /** draw의 weighted_v2 계산과 가중 순위 추출 코어를 그대로 사용합니다. */
    public function __construct(private WeightedState $state, private BuildRecommendationWeightProfile $profiles, private GenerateWeightedRecommendations $generator) {}

    /** 계산 전후 지문을 검증하고 정정된 모델은 기존 발급 이력을 보존한 새 개정으로 저장합니다. */
    public function execute(?int $target, int $candidates, int $entries, int $seed, bool $apply, ?callable $progress = null): array
    {
        if ($candidates < 1 || $candidates > 500000 || $entries < 1 || $entries > 50000 || $entries > $candidates || $seed < 1) {
            throw new RuntimeException('후보 1~500000, 저장 후보 1~50000 및 후보 수 이하, seed 1 이상이어야 합니다.');
        }
        $snapshot = DB::transaction(fn () => $this->state->snapshot($target));
        $this->assertNotPrepared($snapshot);
        $this->state->assertCombinations();
        $profile = $this->profiles->build($snapshot['basis_round']);
        $generated = $this->generator->generate($snapshot['basis_round'], $entries, $candidates, 5, $seed, $progress, $profile, false);
        $profileJson = json_encode($profile, JSON_THROW_ON_ERROR);

        return DB::transaction(function () use ($snapshot, $generated, $profileJson, $seed, $apply): array {
            if ($this->state->snapshot($snapshot['target_round']) !== $snapshot) {
                throw new RuntimeException('계산 중 당첨 이력이나 설정이 변경되었습니다. 다시 준비하세요.');
            }
            $this->assertNotPrepared($snapshot);
            $result = ['applied' => $apply, ...$snapshot, 'seed' => $seed, 'candidate_count' => $generated->candidatePoolSize, 'entry_count' => count($generated->games), 'model_hash' => hash('sha256', $profileJson)];
            if (! $apply) {
                return $result;
            }
            DB::table('weighted_pools')->where('ready', true)->update(['ready' => false]);
            $modelId = DB::table('weighted_models')->insertGetId([...$snapshot, 'algorithm' => 'weighted-v2', 'model_hash' => $result['model_hash'], 'profile' => $profileJson, 'created_at' => now()]);
            $poolId = DB::table('weighted_pools')->insertGetId(['model_id' => $modelId, 'target_round' => $snapshot['target_round'], 'ready' => true, 'seed' => $seed, 'candidate_count' => $result['candidate_count'], 'entry_count' => $result['entry_count'], 'historical_winner_count' => $generated->historicalWinningCombinationCount, 'created_at' => now()]);
            foreach (array_chunk($generated->games, 500) as $chunk) {
                DB::table('weighted_pool_entries')->insert(array_map(fn ($game) => ['pool_id' => $poolId, 'sequence' => $game['sequence'], 'combination_id' => $game['combination_id'], 'sampling_weight' => $game['sampling_weight'], ...array_combine(['number1', 'number2', 'number3', 'number4', 'number5', 'number6'], $game['numbers'])], $chunk));
            }

            return [...$result, 'model_id' => $modelId, 'pool_id' => $poolId];
        });
    }
    /** 유효한 같은 모델은 재계산하지 않되 정정 후 무효화된 모델은 재준비할 수 있습니다. */
    private function assertNotPrepared(array $snapshot): void
    {
        $exists = DB::table('weighted_pools as p')
            ->join('weighted_models as m', 'm.id', '=', 'p.model_id')
            ->where('p.target_round', $snapshot['target_round'])
            ->where('p.ready', true)
            ->where('m.draws_hash', $snapshot['draws_hash'])
            ->where('m.config_hash', $snapshot['config_hash'])
            ->exists();
        if ($exists) {
            throw new RuntimeException('동일 기준의 가중 모델이 이미 준비되어 있습니다. 기존 풀을 사용하세요.');
        }
    }

}
