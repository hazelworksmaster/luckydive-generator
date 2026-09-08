<?php

namespace App\Algorithms\Contracts;

interface ContextualNumberGenerator extends NumberGenerator
{
    /** 후보 기준과 함께 생성하고 요청 회차가 준비 대상과 다르면 거부합니다. */
    public function generateContext(int $count, ?int $drawNo): array;
}
