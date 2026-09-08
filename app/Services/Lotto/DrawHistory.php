<?php

namespace App\Services\Lotto;

use App\Dto\Lotto\LottoDrawData;
use App\Services\Filtered\PipelineState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class DrawHistory
{
    /** 당첨 이력 변경과 후보 무효화를 한 번에 처리합니다. */
    public function __construct(private PipelineState $state, private LottoDrawCalendar $calendar) {}

    /** CSV의 형식 오류를 묵시적 정수 변환으로 숨기지 않습니다. */
    public function fromArray(array $row): LottoDrawData
    {
        $values = [];
        foreach (['round', 'number1', 'number2', 'number3', 'number4', 'number5', 'number6', 'bonus'] as $key) {
            $v = filter_var($row[$key] ?? null, FILTER_VALIDATE_INT);
            if ($v === false || $v < 1) {
                throw new RuntimeException('잘못된 당첨번호 필드: '.$key);
            }
            $values[$key] = $v;
        }
        $date = (string) ($row['date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('CSV 추첨일은 YYYY-MM-DD 형식이어야 합니다.');
        }
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Seoul');
        if ($parsed->format('Y-m-d') !== $date) {
            throw new RuntimeException('유효하지 않은 추첨일입니다.');
        }

        return new LottoDrawData($values['round'], array_map(fn ($i) => $values['number'.$i], range(1, 6)), $values['bonus'], $parsed);
    }

    /** 전체 입력을 먼저 검증하고 적용 시 신규·정정 내용만 저장합니다. */
    public function store(array $draws, string $source, bool $apply): array
    {
        $incoming = [];
        $latest = $this->calendar->latestAnnouncedDrawNo();
        foreach ($draws as $draw) {
            if ($draw->drawNo > $latest || isset($incoming[$draw->drawNo])) {
                throw new RuntimeException('미래 또는 중복 회차는 반입할 수 없습니다.');
            }
            $row = $draw->toDatabaseAttributes();
            $incoming[$draw->drawNo] = [...$row, 'source' => $source, 'content_hash' => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR))];
        }
        $work = function () use ($incoming, $apply): array {
            if ($apply) {
                $this->state->lock();
            }
            $summary = ['applied' => $apply, 'created' => 0, 'updated' => 0, 'unchanged' => 0];
            foreach ($incoming as $round => $row) {
                $old = DB::table('winning_numbers')->where('round', $round)->first();
                $kind = $old === null ? 'created' : ($old->content_hash === $row['content_hash'] ? 'unchanged' : 'updated');
                $summary[$kind]++;
                if ($apply && $kind !== 'unchanged') {
                    DB::table('winning_numbers')->updateOrInsert(['round' => $round], [...$row, 'updated_at' => now()]);
                }
            }
            if ($apply && $summary['created'] + $summary['updated'] > 0) {
                DB::table('filtered_pipeline_state')->where('id', 1)->update(['ready' => false]);
                /** 가중 migration 전에도 수집을 허용하며 적용된 후보는 정정 즉시 무효화합니다. */
                if (Schema::hasTable('weighted_pools')) {
                    DB::table('weighted_pools')->where('ready', true)->update(['ready' => false]);
                }
            }

            return $summary;
        };

        return $apply ? DB::transaction($work) : $work();
    }
}
