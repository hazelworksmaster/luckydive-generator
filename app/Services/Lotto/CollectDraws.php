<?php

namespace App\Services\Lotto;

use App\Contracts\Lotto\LottoDrawClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CollectDraws
{
    /** 직접 수집은 기존 draw 서비스나 DB 연결을 사용하지 않습니다. */
    public function __construct(private LottoDrawClient $client, private LottoDrawCalendar $calendar, private DrawHistory $history) {}

    /** 기본은 누락 회차와 최신 회차 재검증이며 과거 정정은 범위 옵션으로 확인합니다. */
    public function execute(?int $from, ?int $to, bool $apply): array
    {
        $latest = $this->calendar->latestAnnouncedDrawNo();
        if ($latest < 1) {
            throw new RuntimeException('발표 대상 회차가 없습니다.');
        }
        if ($from !== null || $to !== null) {
            $from ??= $to;
            $to ??= $from;
            if ($from < 1 || $from > $to || $to > $latest) {
                throw new RuntimeException('수집 회차 범위가 올바르지 않습니다.');
            }
            $targets = range($from, $to);
        } else {
            $stored = DB::table('winning_numbers')->pluck('round')->map(fn ($v) => (int) $v)->all();
            $targets = array_values(array_unique([...array_diff(range(1, $latest), $stored), $latest]));
            sort($targets);
        }
        $draws = [];
        foreach ($targets as $index => $round) {
            $draw = $this->client->fetch($round);
            if ($draw === null) {
                throw new RuntimeException($round.'회 결과가 아직 없으므로 이번 수집은 저장하지 않습니다.');
            }
            $draws[] = $draw;
            if ($index < count($targets) - 1) {
                usleep(300000);
            }
        }

        return ['requested' => count($targets), ...$this->history->store($draws, 'dhlottery', $apply)];
    }
}
