<?php

namespace App\Console\Commands;

use App\Services\Lotto\CollectDraws;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Throwable;

class CollectDrawsCommand extends Command
{
    protected $signature = 'lotto:sync-draws {--from= : 시작 회차} {--to= : 종료 회차} {--apply : 검증 후 저장} {--dry-run : 저장 없는 사전 확인}';

    protected $description = '공식 당첨번호를 직접 수집하며 기본 실행은 미저장입니다';

    /** 입력과 통신 오류는 실패 종료하며 실제 비밀 접속값은 출력하지 않습니다. */
    public function handle(CollectDraws $collector): int
    {
        try {
            if ($this->option('apply') && $this->option('dry-run')) {
                throw new \RuntimeException('--apply와 --dry-run은 동시에 사용할 수 없습니다.');
            }
            $values = [];
            foreach (['from', 'to'] as $key) {
                $raw = $this->option($key);
                $v = $raw === null ? null : filter_var($raw, FILTER_VALIDATE_INT);
                if ($v === false) {
                    throw new \RuntimeException('회차는 정수여야 합니다.');
                }$values[] = $v;
            }
            $this->line(json_encode($collector->execute(...[...$values, (bool) $this->option('apply')]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e instanceof QueryException ? 'DB 조회 또는 저장 실패: 연결 설정과 migration을 확인하세요.' : $e->getMessage());

            return self::FAILURE;
        }
    }
}
