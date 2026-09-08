<?php

namespace App\Console\Commands;

use App\Services\Weighted\BuildCombinations;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use RuntimeException;

final class BuildCombinationsCommand extends Command
{
    protected $signature = 'lotto:build-combinations {--apply : 실제 적재} {--dry-run : 저장 없는 계획 확인} {--max-rows= : 이번 적재 최대 건수}';

    protected $description = '전체 조합과 고정 통계 특성을 독립 생성하며 중단 후 재개합니다';

    /** 기본은 읽기 전용 계획이며 명시적인 apply에서만 적재합니다. */
    public function handle(BuildCombinations $builder): int
    {
        try {
            if ($this->option('apply') && $this->option('dry-run')) {
                throw new RuntimeException('--apply와 --dry-run은 함께 사용할 수 없습니다.');
            }
            $raw = $this->option('max-rows');
            $max = $raw === null ? null : filter_var($raw, FILTER_VALIDATE_INT);
            if ($max === false) {
                throw new RuntimeException('max-rows는 정수여야 합니다.');
            }
            $result = $builder->execute((bool) $this->option('apply'), $max, fn ($done, $total) => $this->info($done.'/'.$total));
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e instanceof QueryException ? 'DB 접속 및 migration을 확인하세요.' : $e->getMessage());

            return self::FAILURE;
        }
    }
}
