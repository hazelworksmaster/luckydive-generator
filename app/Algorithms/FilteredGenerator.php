<?php

namespace App\Algorithms;

use App\Algorithms\Contracts\ContextualNumberGenerator;
use App\Algorithms\Rules\PreviousGameOverlap;
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

    /** 연속 순번의 무작위 후보를 묶어 조회하고 직전 확정 게임과의 중복을 검사합니다. */
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
            $overlap = new PreviousGameOverlap;
            $games = $selected = [];
            $attempts = 0;
            /** DB 조회를 묶되 난수 순서를 유지하고, 거부한 후보는 다음 게임에서 다시 허용합니다. */
            while (count($games) < $count && $attempts < 10000) {
                $ids = [];
                $batchSize = min(100, 10000 - $attempts);
                for ($i = 0; $i < $batchSize; $i++) {
                    $ids[] = random_int(1, $total);
                }
                $attempts += $batchSize;
                $rows = DB::table('filter6_numbers')->whereIn('id', array_unique($ids))->get()->keyBy('id');
                if ($rows->count() !== count(array_unique($ids))) {
                    throw new RuntimeException('후보 순번이 손상되어 다시 준비해야 합니다.');
                }
                foreach ($ids as $id) {
                    if (isset($selected[$id])) {
                        continue;
                    }
                    $game = $this->rules->numbers((array) $rows[$id]);
                    if (! $overlap->allows($game, $games)) {
                        continue;
                    }
                    $selected[$id] = true;
                    $games[] = $game;
                    if (count($games) === $count) {
                        break;
                    }
                }
            }
            /** 제한에 도달하면 조건을 완화하거나 부분 결과를 저장하지 않습니다. */
            if (count($games) !== $count) {
                throw new RuntimeException('직전 게임과 최대 2개 중복 조건을 만족하는 필터 조합을 만들지 못했습니다. 후보 구성을 확인하거나 다시 실행하세요.');
            }

            return ['games' => $games, 'draw_no' => $target, 'metadata' => ['basis_round' => $latest, 'draws_hash' => $hash, 'base_version' => $state->base_version, 'prepared_at' => $state->prepared_at, 'statistics' => json_decode($state->statistics, true, 512, JSON_THROW_ON_ERROR)]];
        });
    }
}
