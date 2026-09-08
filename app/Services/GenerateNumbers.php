<?php

namespace App\Services;

use App\Algorithms\Contracts\ContextualNumberGenerator;
use App\Algorithms\Contracts\RecordsSelections;
use App\Algorithms\GeneratorRegistry;
use App\Models\RecommendationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class GenerateNumbers
{
    /** 실행 경로와 관계없이 같은 생성 계약을 사용합니다. */
    public function __construct(private GeneratorRegistry $generators) {}

    /** 명시적으로 요청한 경우만 저장하며 외부 API 제출은 수행하지 않습니다. */
    public function execute(int $count, ?int $drawNo = null, bool $save = false, ?string $algorithm = null): array
    {
        if ($drawNo !== null && $drawNo < 1) {
            throw new InvalidArgumentException('회차는 1 이상이어야 합니다.');
        }

        $generator = $this->generators->resolve($algorithm);

        $work = function () use ($generator, $count, $drawNo, $save): array {
            $context = $generator instanceof ContextualNumberGenerator ? $generator->generateContext($count, $drawNo) : null;
            $result = [
                'id' => (string) Str::uuid(),
                'algorithm' => $generator->identifier(),
                'draw_no' => $context['draw_no'] ?? $drawNo,
                'game_count' => $count,
                'games' => $context['games'] ?? $generator->generate($count),
                'status' => 'generated',
            ];

            if ($context !== null) {
                $result['metadata'] = $context['metadata'];
            }

            if ($save) {
                RecommendationRun::query()->create($result);
                /** 발급 이력까지 성공해야 공통 실행 원장도 확정합니다. */
                if ($generator instanceof RecordsSelections) {
                    $generator->recordSelections($result['id'], $context);
                }
            }

            return [...$result, 'saved' => $save];
        };

        /** 후보 조회부터 생성 원장 저장까지 같은 잠금을 유지합니다. */
        return $generator instanceof ContextualNumberGenerator ? DB::transaction($work) : $work();
    }
}
