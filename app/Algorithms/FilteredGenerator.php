<?php

namespace App\Algorithms;

use App\Algorithms\Contracts\ContextualNumberGenerator;
use App\Services\Filtered\FilterRules;
use App\Services\Filtered\PipelineState;
use App\Services\Lotto\LottoDrawCalendar;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class FilteredGenerator implements ContextualNumberGenerator
{
    /** 최신 완료 후보의 기준과 번호를 같은 transaction에서 읽습니다. */
    public function __construct(private PipelineState $state, private FilterRules $rules, private LottoDrawCalendar $calendar) {}

    /** 필터 규칙 변경 시 새 버전으로 분리합니다. */
    public function identifier(): string
    {
        return 'filtered-v1';
    }

    /** 공통 계약 호출도 최신 회차 검증을 수행합니다. */
    public function generate(int $count): array
    {
        return $this->generateContext($count, null)['games'];
    }

    /** 연속 순번을 균등 추출하고 후보를 일괄 조회합니다. */
    public function generateContext(int $count, ?int $drawNo): array
    {
        if ($count < 1 || $count > 100) {
            throw new InvalidArgumentException('게임 수는 1~100 사이여야 합니다.');
        }

        return DB::transaction(function () use ($count, $drawNo): array {
            $state = $this->state->lock();
            [$draws,$hash] = $this->state->history();
            $latest = $this->calendar->latestAnnouncedDrawNo();
            $this->state->assertComplete($draws, $latest);
            if (! $state->ready || (int) $state->basis_round !== $latest || $state->draws_hash !== $hash || $state->algorithm !== $this->identifier()) {
                throw new RuntimeException('최신 필터 후보를 먼저 준비하세요.');
            }
            $target = $latest + 1;
            if ($drawNo !== null && $drawNo !== $target) {
                throw new InvalidArgumentException('현재 준비된 추천 대상 회차는 '.$target.'입니다.');
            }
            $total = (int) $state->filter6_count;
            if ($total < $count) {
                throw new RuntimeException('요청한 게임 수만큼 후보가 없습니다.');
            }
            /** 연속 후보 ID에서 서로 다른 난수를 뽑아 한 번에 조회합니다. */
            $ids = [];
            while (count($ids) < $count) {
                $ids[random_int(1, $total)] = true;
            }
            $rows = DB::table('filter6_numbers')->whereIn('id', array_keys($ids))->get()->keyBy('id');
            if ($rows->count() !== $count) {
                throw new RuntimeException('후보 순번이 손상되어 다시 준비해야 합니다.');
            }
            /** DB 반환 순서와 무관하게 난수를 추출한 순서로 게임을 반환합니다. */
            $games = [];
            foreach (array_keys($ids) as $id) {
                $games[] = $this->rules->numbers((array) $rows[$id]);
            }

            return ['games' => $games, 'draw_no' => $target, 'metadata' => ['basis_round' => $latest, 'draws_hash' => $hash, 'base_version' => $state->base_version, 'prepared_at' => $state->prepared_at, 'statistics' => json_decode($state->statistics, true, 512, JSON_THROW_ON_ERROR)]];
        });
    }
}
