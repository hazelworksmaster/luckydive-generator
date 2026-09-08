<?php

namespace App\Services\Weighted;

use App\Services\Filtered\FilterRules;
use App\Services\Filtered\PipelineState;
use App\Services\Lotto\CalculateCombinationFeatures;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BuildCombinations
{
    public const TOTAL = 8145060;

    /** draw DB 없이 동일 사전식 순번과 고정 특성을 생성합니다. */
    public function __construct(private FilterRules $rules, private CalculateCombinationFeatures $features, private PipelineState $state) {}

    /** 마지막 정상 배치 이후부터 재개하며 완료 전에는 모델 준비를 허용하지 않습니다. */
    public function execute(bool $apply, ?int $maxRows = null, ?callable $progress = null): array
    {
        if ($maxRows !== null && $maxRows < 1) {
            throw new RuntimeException('max-rows는 1 이상이어야 합니다.');
        }
        $before = DB::table('lotto_combinations')->count();
        $last = (int) DB::table('lotto_combinations')->max('id');
        $first = (int) DB::table('lotto_combinations')->min('id');
        if ($before !== $last || ($before > 0 && $first !== 1) || $last > self::TOTAL) {
            throw new RuntimeException('전체 조합 순번이 손상되어 자동 재개할 수 없습니다.');
        }
        $planned = min(self::TOTAL - $last, $maxRows ?? self::TOTAL);
        $inserted = 0;
        if ($apply && $planned > 0) {
            $buffer = [];
            $chunk = DB::getDriverName() === 'sqlite' ? 500 : 2000;
            $flush = function () use (&$buffer, &$last, &$inserted, $progress): void {
                DB::transaction(function () use (&$buffer, &$last): void {
                    $this->state->lock();
                    if ((int) DB::table('lotto_combinations')->max('id') !== $last) {
                        throw new RuntimeException('다른 조합 적재가 실행 중입니다. 완료 후 재실행하세요.');
                    }
                    DB::table('lotto_combinations')->insert($buffer);
                });
                $last = $buffer[array_key_last($buffer)]['id'];
                $inserted += count($buffer);
                $buffer = [];
                if ($progress !== null && ($inserted % 100000 === 0 || $last === self::TOTAL)) {
                    $progress($last, self::TOTAL);
                }
            };
            foreach ($this->rules->combinations() as $id => $numbers) {
                if ($id <= $last) {
                    continue;
                }
                $buffer[] = ['id' => $id, ...array_combine(['number1', 'number2', 'number3', 'number4', 'number5', 'number6'], $numbers), ...$this->features->calculate($numbers)];
                if (count($buffer) >= $chunk || $inserted + count($buffer) === $planned) {
                    $flush();
                }
                if ($inserted === $planned) {
                    break;
                }
            }
        }

        return ['applied' => $apply, 'before' => $before, 'planned' => $planned, 'inserted' => $inserted, 'after' => $before + $inserted, 'complete' => $before + $inserted === self::TOTAL];
    }
}
