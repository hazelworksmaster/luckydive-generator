<?php

namespace App\Console\Commands;

use App\Services\Submission\SubmitRecommendations;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

final class SubmitRecommendationsCommand extends Command
{
    protected $signature = 'lotto:submit {--profile= : AI 별칭 random/filtered/weighted/overdue} {--request-id= : 재시도할 원래 요청 UUID} {--apply : 생성 이력 저장 및 실제 API 등록} {--dry-run : context 및 전송 계획만 확인} {--prepare-only : apply와 함께 사용해 요청 저장까지만 실행}';

    protected $description = 'AI별 토큰으로 정확히 5게임을 등록하며 실패 시 같은 UUID로 재전송합니다';

    /** 새 UUID를 먼저 출력해 장애가 나도 같은 요청으로 재개할 수 있게 합니다. */
    public function handle(SubmitRecommendations $service): int
    {
        try {
            if (! $this->option('profile') || ($this->option('apply') && $this->option('dry-run')) || ($this->option('prepare-only') && ! $this->option('apply'))) {
                throw new RuntimeException('profile을 지정하고 apply/dry-run 중 하나를 사용하세요. prepare-only에는 apply가 필요합니다.');
            }
            $id = $this->option('request-id') ?? (string) Str::uuid();
            $this->info('request_id: '.$id);
            $result = $service->execute($this->option('profile'), $id, (bool) $this->option('apply'), (bool) $this->option('prepare-only'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return in_array($result['status'], ['preview', 'pending', 'submitted'], true) ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e instanceof RuntimeException && ! $e instanceof QueryException ? $e->getMessage() : '등록 처리에 실패했습니다. DB 연결·migration과 요청 UUID를 확인하세요.');

            return self::FAILURE;
        }
    }
}
