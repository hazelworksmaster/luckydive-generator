<?php

namespace App\Services\Lotto;

use Carbon\CarbonImmutable;

/**
 * 로또645 추첨 시각을 기준으로 동기화 대상 회차를 계산한다.
 */
final class LottoDrawCalendar
{
    private const FIRST_DRAW_AT = '2002-12-07 20:45:00';

    public function latestAnnouncedDrawNo(?CarbonImmutable $now = null): int
    {
        $now = ($now ?? CarbonImmutable::now('Asia/Seoul'))->setTimezone('Asia/Seoul');
        $firstDrawAt = CarbonImmutable::parse(self::FIRST_DRAW_AT, 'Asia/Seoul');

        if ($now->lessThan($firstDrawAt)) {
            return 0;
        }

        // 추첨 발표 시각 이전 토요일에는 아직 직전 회차가 최신 회차다.
        return intdiv((int) $firstDrawAt->diffInDays($now), 7) + 1;
    }
}
