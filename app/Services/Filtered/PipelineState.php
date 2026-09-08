<?php

namespace App\Services\Filtered;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PipelineState
{
    /** 수집·후보 교체·선택은 같은 DB 행 잠금을 사용합니다. 반드시 transaction 안에서 호출합니다. */
    public function lock(): object
    {
        return DB::table('filtered_pipeline_state')->where('id', 1)->lockForUpdate()->first()
            ?? throw new RuntimeException('필터 테이블 migration이 필요합니다.');
    }

    /** 캐시가 아닌 공식 이력 내용으로 준비 후보의 유효성을 검사합니다. */
    public function history(): array
    {
        $rows = DB::table('winning_numbers')->orderBy('round')->get()->map(fn ($r) => (array) $r)->all();
        $hash = hash('sha256', json_encode(array_column($rows, 'content_hash'), JSON_THROW_ON_ERROR));

        return [$rows, $hash];
    }

    /** 중간 누락·미래 회차·아직 수집하지 않은 최신 회차를 허용하지 않습니다. */
    public function assertComplete(array $rows, int $latest): void
    {
        if ($latest < 1 || array_map(fn ($r) => (int) $r['round'], $rows) !== range(1, $latest)) {
            throw new RuntimeException('당첨 이력이 1회부터 현재 최신 회차까지 완전하지 않습니다. 동기화를 먼저 실행하세요.');
        }
    }
}
