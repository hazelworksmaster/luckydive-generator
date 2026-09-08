<?php

namespace App\Algorithms;

use App\Algorithms\Contracts\RecordsSelections;
use App\Services\Filtered\FilterRules;
use App\Services\Weighted\WeightedState;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

final class WeightedGenerator implements RecordsSelections
{
    /** 준비된 후보만 읽으며 회차 전체의 저장된 동일 조합을 제외합니다. */
    public function __construct(private WeightedState $state, private FilterRules $rules) {}

    /** draw의 weighted_v2 모델에 대응하는 공개 식별자입니다. */
    public function identifier(): string
    {
        return 'weighted-v2';
    }

    /** 저장 없는 계약 호출은 후보를 소비하지 않습니다. */
    public function generate(int $count): array
    {
        return $this->generateContext($count, null)['games'];
    }

    /** draw의 난수 시작 순번 이후 첫 미발급 후보 선택과 처음으로 돌아가는 탐색을 유지합니다. */
    public function generateContext(int $count, ?int $drawNo): array
    {
        if ($count < 1 || $count > 100) {
            throw new RuntimeException('게임 수는 1~100 사이여야 합니다.');
        }

        return DB::transaction(function () use ($count, $drawNo): array {
            $snapshot = $this->state->snapshot($drawNo);
            $pool = DB::table('weighted_pools')->where('target_round', $snapshot['target_round'])->where('ready', true)->orderByDesc('id')->lockForUpdate()->first();
            if ($pool === null) {
                throw new RuntimeException('최신 가중 후보를 먼저 준비하세요.');
            }
            $model = DB::table('weighted_models')->where('id', $pool->model_id)->first();
            if ($model === null || $model->algorithm !== $this->identifier() || $model->draws_hash !== $snapshot['draws_hash'] || $model->config_hash !== $snapshot['config_hash']) {
                throw new RuntimeException('당첨 이력 또는 설정이 변경되어 가중 후보를 다시 준비해야 합니다.');
            }
            $seed = random_int(1, 2147483646);
            $randomizer = new Randomizer(new Mt19937($seed));
            $excluded = [];
            $games = [];
            $selection = [];
            while (count($games) < $count) {
                $start = $randomizer->getInt(1, (int) $pool->entry_count);
                while (true) {
                    $query = DB::table('weighted_pool_entries as e')
                        ->where('e.pool_id', $pool->id)
                        ->whereNotIn('e.combination_id', DB::table('weighted_selections')->select('combination_id')->where('target_round', $snapshot['target_round']))
                        ->when($excluded !== [], fn ($q) => $q->whereNotIn('e.id', $excluded));
                    $row = (clone $query)->where('e.sequence', '>=', $start)->orderBy('e.sequence')->first()
                        ?? (clone $query)->where('e.sequence', '<', $start)->orderBy('e.sequence')->first();
                    if ($row === null) {
                        throw new RuntimeException('요청한 게임 수만큼 미발급 가중 후보가 없습니다.');
                    }
                    $excluded[] = (int) $row->id;
                    $numbers = $this->rules->numbers((array) $row);
                    $winner = DB::table('winning_numbers')->where('round', '<=', $snapshot['basis_round']);
                    foreach ($numbers as $index => $number) {
                        $winner->where('number'.($index + 1), $number);
                    }
                    if ($winner->exists()) {
                        continue;
                    }
                    $games[] = $numbers;
                    $selection[] = ['entry_id' => (int) $row->id, 'combination_id' => (int) $row->combination_id];
                    break;
                }
            }

            return ['games' => $games, 'draw_no' => $snapshot['target_round'], 'metadata' => [...$snapshot, 'model_id' => $model->id, 'model_hash' => $model->model_hash, 'pool_id' => $pool->id, 'selection_seed' => $seed, 'selections' => $selection]];
        });
    }

    /** 공통 실행 원장과 발급 고유 제약을 함께 저장해 부분 저장과 동시 중복을 방지합니다. */
    public function recordSelections(string $runId, array $context): void
    {
        $metadata = $context['metadata'];
        foreach ($metadata['selections'] as $index => $selection) {
            DB::table('weighted_selections')->insert([...$selection, 'run_id' => $runId, 'target_round' => $context['draw_no'], 'sequence' => $index + 1, 'selection_seed' => $metadata['selection_seed'], 'created_at' => now()]);
        }
    }
}
