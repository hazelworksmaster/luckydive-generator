<?php

namespace App\Services\Weighted;

use App\Services\Filtered\PipelineState;
use App\Services\Lotto\LottoDrawCalendar;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WeightedState
{
    /** 수집・필터・가중 추천이 동일 잠금 순서로 실행되도록 공용 잠금을 재사용합니다. */
    public function __construct(private PipelineState $state, private LottoDrawCalendar $calendar) {}

    /** 당첨번호 정정과 설정 변경을 지문으로 검출해 오래된 후보 사용을 차단합니다. */
    public function snapshot(?int $target = null): array
    {
        $this->state->lock();
        [$draws, $hash] = $this->state->history();
        $latest = $this->calendar->latestAnnouncedDrawNo();
        $this->state->assertComplete($draws, $latest);
        if ($target !== null && $target !== $latest + 1) {
            throw new RuntimeException('가중 추천 대상은 최신 회차 다음인 '.($latest + 1).'회입니다.');
        }

        return ['target_round' => $latest + 1, 'basis_round' => $latest, 'draws_hash' => $hash, 'config_hash' => hash('sha256', json_encode(config('lotto.recommendation_model'), JSON_THROW_ON_ERROR))];
    }

    /** 전체 조합이 완성되지 않으면 부분 분포를 실제 모델로 사용하지 않습니다. */
    public function assertCombinations(): void
    {
        $query = DB::table('lotto_combinations');
        if ($query->count() !== BuildCombinations::TOTAL || (int) $query->min('id') !== 1 || (int) $query->max('id') !== BuildCombinations::TOTAL) {
            throw new RuntimeException('전체 8145060개 조합을 먼저 준비하세요.');
        }
    }
}
