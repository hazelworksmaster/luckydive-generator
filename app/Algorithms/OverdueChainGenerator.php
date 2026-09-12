<?php

namespace App\Algorithms;

use App\Algorithms\Contracts\ContextualNumberGenerator;
use App\Services\Lotto\LottoDrawCalendar;
use App\Services\Overdue\ChainPlanner;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class OverdueChainGenerator implements ContextualNumberGenerator
{
    /** 후보 테이블 없이 한 번 읽은 당첨 이력만으로 결정적인 조합을 만듭니다. */
    public function __construct(private LottoDrawCalendar $calendar, private ChainPlanner $planner) {}

    /** 시작 번호 선정과 궁합 동률 규칙의 버전을 고정합니다. */
    public function identifier(): string
    {
        return 'overdue-chain-v1';
    }

    /** 기본 회차는 공식 추첨 시각 기준 다음 회차입니다. */
    public function generate(int $count): array
    {
        return $this->generateContext($count, null)['games'];
    }

    /** 과거 재현도 직전 회차까지만 읽어 미래 당첨 이력이 섞이지 않도록 합니다. */
    public function generateContext(int $count, ?int $drawNo): array
    {
        if ($count !== 5) {
            throw new InvalidArgumentException('overdue-chain-v1은 정확히 5게임만 생성합니다.');
        }
        $latest = $this->calendar->latestAnnouncedDrawNo();
        $target = $drawNo ?? $latest + 1;
        if ($target < 2 || $target > $latest + 1) {
            throw new InvalidArgumentException('추천 회차는 2회부터 최신 추첨 다음 회차까지 가능합니다.');
        }
        $basis = $target - 1;
        $rows = DB::table('winning_numbers')->where('round', '<=', $basis)->orderBy('round')
            ->get(['round', 'number1', 'number2', 'number3', 'number4', 'number5', 'number6'])
            ->map(fn ($row) => (array) $row)->all();
        $result = $this->planner->build($rows, $basis);

        return ['games' => $result['games'], 'draw_no' => $target, 'metadata' => [
            /** 기존 요청과 구별할 수 있도록 게임 간 중복 제외 규칙을 기록합니다. */
            'selection_rule' => 'disjoint-games-v2',
            'basis_round' => $basis, 'draws_hash' => $result['draws_hash'],
            'attempted_seeds' => $result['attempted_seeds'], 'traces' => $result['traces'],
        ]];
    }
}
