<?php

namespace App\Console\Commands;

use App\Services\Filtered\PrepareFilteredCandidates;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Throwable;

class PrepareFilteredCandidatesCommand extends Command
{
    protected $signature = 'lotto:prepare-filtered {--apply : 검증된 후보를 DB에 반영} {--dry-run : 저장 없이 예정 건수 계산}';

    protected $description = '최신 기준 filter5·filter6 후보를 준비합니다';

    /** 기본은 읽기 전용 계획 계산이며 명시한 경우에만 후보를 교체합니다. */
    public function handle(PrepareFilteredCandidates $service): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('--apply와 --dry-run은 동시에 사용할 수 없습니다.');

            return self::FAILURE;
        }
        try {
            $this->line(json_encode($service->execute((bool) $this->option('apply'), fn (int $count) => $this->line('처리 중: '.number_format($count).'건 (commit 전)')), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e instanceof QueryException ? 'DB 조회 또는 저장 실패: 연결 설정과 migration을 확인하세요.' : $e->getMessage());

            return self::FAILURE;
        }
    }
}
