<?php

namespace App\Dto\Lotto;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * 동행복권 응답에서 필요한 회차 결과만 분리한 불변 값 객체.
 */
final readonly class LottoDrawData
{
    /**
     * @param  array<int, int>  $numbers
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public int $drawNo,
        public array $numbers,
        public int $bonus,
        public CarbonImmutable $drawDate,
        public array $raw = [],
    ) {
        $this->validate();
        $sorted = $this->numbers;
        sort($sorted, SORT_NUMERIC);
        if ($sorted !== $this->numbers) {
            throw new InvalidArgumentException('당첨번호는 오름차순이어야 합니다.');
        }
        $expected = CarbonImmutable::parse('2002-12-07', 'Asia/Seoul')->addWeeks($this->drawNo - 1)->toDateString();
        if ($this->drawDate->toDateString() !== $expected) {
            throw new InvalidArgumentException('회차와 추첨일이 일치하지 않습니다.');
        }
    }

    /**
     * 외부 응답이 DB에 들어가기 전에 로또 번호 규칙을 만족하는지 확인한다.
     */
    private function validate(): void
    {
        if ($this->drawNo < 1) {
            throw new InvalidArgumentException('회차 번호는 1 이상이어야 합니다.');
        }

        if (count($this->numbers) !== 6) {
            throw new InvalidArgumentException('당첨번호는 정확히 6개여야 합니다.');
        }

        $uniqueNumbers = array_unique($this->numbers);

        if (count($uniqueNumbers) !== 6) {
            throw new InvalidArgumentException('당첨번호에는 중복이 있을 수 없습니다.');
        }

        foreach ([...$this->numbers, $this->bonus] as $number) {
            if ($number < 1 || $number > 45) {
                throw new InvalidArgumentException('로또 번호는 1부터 45 사이여야 합니다.');
            }
        }

        if (in_array($this->bonus, $this->numbers, true)) {
            throw new InvalidArgumentException('보너스 번호는 당첨번호와 중복될 수 없습니다.');
        }
    }

    /**
     * 저장 계층이 컬럼명을 알 수 있게 명시적인 배열 형태로 변환한다.
     *
     * @return array<string, mixed>
     */
    public function toDatabaseAttributes(): array
    {
        return [
            'round' => $this->drawNo,
            'number1' => $this->numbers[0],
            'number2' => $this->numbers[1],
            'number3' => $this->numbers[2],
            'number4' => $this->numbers[3],
            'number5' => $this->numbers[4],
            'number6' => $this->numbers[5],
            'bonus' => $this->bonus,
            'date' => $this->drawDate->toDateString(),
        ];
    }
}
