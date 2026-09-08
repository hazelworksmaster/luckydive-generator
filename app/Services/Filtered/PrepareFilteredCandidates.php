<?php

namespace App\Services\Filtered;

use App\Services\Lotto\LottoDrawCalendar;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PrepareFilteredCandidates
{
    /** 최신 후보 한 벌만 계산하며 실패 시 이전 완료본을 보존합니다. */
    public function __construct(private PipelineState $state, private FilterRules $rules, private LottoDrawCalendar $calendar) {}

    /** DELETE와 INSERT를 한 transaction에 넣어 TRUNCATE의 암묵적 commit을 피합니다. */
    public function execute(bool $apply, ?callable $progress = null): array
    {
        $work = function () use ($apply, $progress): array {
            $state = $apply ? $this->state->lock() : DB::table('filtered_pipeline_state')->where('id', 1)->first();
            if (! $state || $state->base_version !== FilterRules::BASE_VERSION || ! $state->base_prepared_at) {
                throw new RuntimeException('고정 후보를 먼저 준비하세요.');
            }
            [$draws,$hash] = $this->state->history();
            $latest = $this->calendar->latestAnnouncedDrawNo();
            $this->state->assertComplete($draws, $latest);
            $stats = $this->rules->statistics($draws);
            $excluded = $this->rules->exclusions($draws);
            if ($apply) {
                DB::table('filter6_numbers')->delete();
                DB::table('filter5_numbers')->delete();
            }
            /** 대량 후보는 원격 왕복 비용을 줄이되 메모리와 바인딩 수를 제한합니다. */
            $batchSize = DB::getDriverName() === 'mysql' ? 5000 : 500;
            $count5 = 0;
            $count6 = 0;
            $scanned = 0;
            $buffer5 = [];
            $buffer6 = [];
            $flush = function () use (&$buffer5, &$buffer6): void {
                if ($buffer5) {
                    DB::table('filter5_numbers')->insert($buffer5);
                }
                if ($buffer6) {
                    DB::table('filter6_numbers')->insert($buffer6);
                }
                $buffer5 = [];
                $buffer6 = [];
            };
            foreach (DB::table('filtered_base_candidates')->orderBy('id')->lazyById($batchSize) as $object) {
                $scanned++;
                $row = (array) $object;
                $n = $this->rules->numbers($row);
                if (isset($excluded[implode(',', $n)])) {
                    continue;
                }
                $count5++;
                if ($apply) {
                    $buffer5[] = $row;
                }
                if (in_array((int) $row['number_range'], $stats['diffs'], true) && in_array((int) $row['number_sum'], $stats['sums'], true)) {
                    $count6++;
                    if ($apply) {
                        $buffer6[] = [...$row, 'id' => $count6];
                    }
                }
                if ($apply && count($buffer5) >= $batchSize) {
                    $flush();
                    if ($progress !== null && $count5 % 100000 === 0) {
                        $progress($count5);
                    }
                }
            }
            if ($scanned !== (int) $state->base_count) {
                throw new RuntimeException('고정 후보 건수가 준비 기록과 다릅니다.');
            }
            if ($count6 === 0) {
                throw new RuntimeException('최종 후보가 없어 이전 결과를 교체하지 않습니다.');
            }
            if ($apply) {
                $flush();
                DB::table('filtered_pipeline_state')->where('id', 1)->update(['ready' => true, 'basis_round' => $latest, 'draws_hash' => $hash, 'algorithm' => 'filtered-v1', 'filter5_count' => $count5, 'filter6_count' => $count6, 'statistics' => json_encode($stats, JSON_THROW_ON_ERROR), 'prepared_at' => now()]);
            }

            return ['applied' => $apply, 'basis_round' => $latest, 'target_round' => $latest + 1, 'filter5_count' => $count5, 'filter6_count' => $count6, 'statistics' => $stats, 'draws_hash' => $hash];
        };

        /** 미저장 계산도 일관된 읽기 스냅샷을 사용합니다. */
        return DB::transaction($work);
    }
}
