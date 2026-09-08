<?php

namespace App\Algorithms\Contracts;

interface NumberGenerator
{
    /** 생성 방식과 버전을 함께 식별합니다. */
    public function identifier(): string;

    /** 전체 조합에서 요청 수량의 서로 다른 게임을 생성합니다.
     * @return list<list<int>>
     */
    public function generate(int $count): array;
}
