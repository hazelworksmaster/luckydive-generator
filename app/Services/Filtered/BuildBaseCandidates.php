<?php

namespace App\Services\Filtered;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BuildBaseCandidates
{
    /** 불변 후보는 최초 한 번 준비하고 반복 실행은 기존 완료본을 유지합니다. */
    public function __construct(private FilterRules $rules, private PipelineState $state) {}

    /** dry-run도 동일한 전체 순회를 사용하지만 DB와 잠금에는 쓰지 않습니다. */
    public function execute(bool $apply, ?callable $progress = null): array
    {
        $build = function () use ($apply, $progress): array {
            if ($apply) {
                $state = $this->state->lock();
                if ($state->base_prepared_at !== null) {
                    if ($state->base_version !== FilterRules::BASE_VERSION) {
                        throw new RuntimeException('고정 후보 버전 전환은 별도 절차가 필요합니다.');
                    }

                    return ['skipped' => true, 'base_count' => $state->base_count];
                }
                DB::table('filtered_base_candidates')->delete();
            }
            /** 원격 MySQL은 45,000개 바인딩 이하로 묶고 SQLite의 변수 제한은 별도로 지킵니다. */
            $batchSize = $apply && DB::getDriverName() === 'mysql' ? 5000 : 500;
            $counts = [0, 0, 0, 0, 0];
            $buffer = [];
            $scanned = 0;
            foreach ($this->rules->combinations() as $id => $n) {
                $scanned++;
                $rejected = $this->rules->rejection($n);
                $counts[$rejected]++;
                if ($rejected === 0 && $apply) {
                    $buffer[] = $this->rules->row($id, $n);
                    if (count($buffer) >= $batchSize) {
                        DB::table('filtered_base_candidates')->insert($buffer);
                        $buffer = [];
                        if ($progress !== null && $counts[0] % 100000 === 0) {
                            $progress($counts[0]);
                        }
                    }
                }
            }
            if ($apply) {
                if ($buffer) {
                    DB::table('filtered_base_candidates')->insert($buffer);
                }
                DB::table('filtered_pipeline_state')->where('id', 1)->update(['base_version' => FilterRules::BASE_VERSION, 'base_count' => $counts[0], 'base_prepared_at' => now(), 'ready' => false]);
            }

            return ['applied' => $apply, 'scanned' => $scanned, 'base_count' => $counts[0], 'excluded_by_first_filter' => array_slice($counts, 1)];
        };

        return $apply ? DB::transaction($build) : $build();
    }
}
