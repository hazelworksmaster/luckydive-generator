<?php

namespace App\Contracts\Lotto;

use App\Dto\Lotto\LottoDrawData;

interface LottoDrawClient
{
    /**
     * 외부 제공처에서 지정 회차의 당첨 결과를 조회한다.
     */
    public function fetch(int $drawNo): ?LottoDrawData;
}
