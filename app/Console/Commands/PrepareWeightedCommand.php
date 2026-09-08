<?php

namespace App\Console\Commands;

use App\Services\Weighted\PrepareWeighted;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use RuntimeException;

final class PrepareWeightedCommand extends Command
{
    protected $signature = 'lotto:prepare-weighted {--target-round= : 추천 대상 회차; 기본 최신 다음 회차} {--candidate-count=100000 : 평가 후보 수} {--entry-count=10000 : 저장 후보 수} {--seed= : 재현용 양의 정수} {--apply : 모델과 후보 저장} {--dry-run : 저장 없이 계산}';

    protected $description = 'weighted_v2 모델과 내부 후보를 준비하며 추천 이력은 생성하지 않습니다';

    /** 통계 계산과 실제 반영을 구분하고 재현에 필요한 seed를 출력합니다. */
    public function handle(PrepareWeighted $service): int
    {
        try {
            if ($this->option('apply') && $this->option('dry-run')) {
                throw new RuntimeException('--apply와 --dry-run은 함께 사용할 수 없습니다.');
            }
            $values = [];
            foreach (['target-round', 'candidate-count', 'entry-count', 'seed'] as $option) {
                $raw = $this->option($option);
                $value = $raw === null ? null : filter_var($raw, FILTER_VALIDATE_INT);
                if ($value === false || ($value !== null && $value < 1)) {
                    throw new RuntimeException($option.'는 양의 정수여야 합니다.');
                }
                $values[] = $value;
            }
            $values[3] ??= random_int(1, 2147483646);
            $result = $service->execute(...[...$values, (bool) $this->option('apply'), fn ($done, $total) => $this->info($done.'/'.$total)]);
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e instanceof QueryException ? 'DB 접속 및 migration을 확인하세요.' : $e->getMessage());

            return self::FAILURE;
        }
    }
}
