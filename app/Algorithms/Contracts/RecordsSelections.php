<?php

namespace App\Algorithms\Contracts;

interface RecordsSelections extends ContextualNumberGenerator
{
    /** 공통 실행 원장 저장과 같은 transaction에서 선택 이력을 확정합니다. */
    public function recordSelections(string $runId, array $context): void;
}
