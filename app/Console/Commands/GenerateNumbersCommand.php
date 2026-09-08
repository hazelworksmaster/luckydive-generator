<?php

namespace App\Console\Commands;

use App\Services\GenerateNumbers;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

class GenerateNumbersCommand extends Command
{
    protected $signature = 'lotto:generate {--algorithm= : 알고리즘 식별자(기본 random-v1)} {--count=5 : 생성 게임 수(1~100)} {--draw-no= : 선택 회차} {--save : 생성기 DB에 결과 저장} {--dry-run : 저장하지 않고 생성}';

    protected $description = '선택한 알고리즘으로 추천번호를 생성합니다';

    /** 랜덤은 DB 없이 생성하고 필터드는 준비 후보를 읽으며 저장 여부를 명확히 표시합니다. */
    public function handle(GenerateNumbers $service): int
    {
        if ($this->option('save') && $this->option('dry-run')) {
            $this->error('--save와 --dry-run은 함께 사용할 수 없습니다.');

            return self::FAILURE;
        }
        $count = filter_var($this->option('count'), FILTER_VALIDATE_INT);
        $rawRound = $this->option('draw-no');
        $round = $rawRound === null ? null : filter_var($rawRound, FILTER_VALIDATE_INT);
        if ($count === false || $round === false) {
            $this->error('게임 수와 회차는 정수로 입력하세요.');

            return self::FAILURE;
        }

        try {
            $result = $service->execute($count, $round, (bool) $this->option('save'), $this->option('algorithm'));
        } catch (\RuntimeException|InvalidArgumentException $exception) {
            $this->error($exception instanceof QueryException ? 'DB 조회 또는 저장 실패: 연결 설정과 migration을 확인하세요.' : $exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
