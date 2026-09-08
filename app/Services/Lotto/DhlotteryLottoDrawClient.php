<?php

namespace App\Services\Lotto;

use App\Contracts\Lotto\LottoDrawClient;
use App\Dto\Lotto\LottoDrawData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 동행복권 로또645 회차 결과 API 호출을 담당한다.
 */
final class DhlotteryLottoDrawClient implements LottoDrawClient
{
    private const RESULT_URL = 'https://www.dhlottery.co.kr/lt645/selectPstLt645InfoNew.do';

    public function fetch(int $drawNo): ?LottoDrawData
    {
        $response = Http::timeout(10)
            ->retry(2, 300)
            ->withHeaders([
                'AJAX' => 'true',
                'requestMenuUri' => '/lt645/result',
                'Accept' => 'application/json',
            ])
            ->get(self::RESULT_URL, [
                'srchDir' => 'center',
                'srchLtEpsd' => (string) $drawNo,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('동행복권 응답 오류: HTTP '.$response->status());
        }

        $items = $response->json('data.list');

        if (! is_array($items)) {
            throw new RuntimeException('동행복권 응답 구조가 올바르지 않습니다.');
        }

        foreach ($items as $item) {
            if (is_array($item) && (int) ($item['ltEpsd'] ?? 0) === $drawNo) {
                return $this->normalize($item);
            }
        }

        return null;
    }

    /**
     * 동행복권 필드명을 앱 내부 표준 필드로 변환한다.
     *
     * @param  array<string, mixed>  $item
     */
    private function normalize(array $item): LottoDrawData
    {
        return new LottoDrawData(
            drawNo: $this->integer($item, 'ltEpsd'),
            numbers: [
                $this->integer($item, 'tm1WnNo'),
                $this->integer($item, 'tm2WnNo'),
                $this->integer($item, 'tm3WnNo'),
                $this->integer($item, 'tm4WnNo'),
                $this->integer($item, 'tm5WnNo'),
                $this->integer($item, 'tm6WnNo'),
            ],
            bonus: $this->integer($item, 'bnsWnNo'),
            drawDate: $this->parseDrawDate((string) ($item['ltRflYmd'] ?? '')),
            raw: $item,
        );
    }

    private function parseDrawDate(string $drawDate): CarbonImmutable
    {
        if (! preg_match('/^\d{8}$/', $drawDate)) {
            throw new RuntimeException('추첨일 형식이 올바르지 않습니다: '.$drawDate);
        }

        $parsed = CarbonImmutable::createFromFormat('!Ymd', $drawDate, 'Asia/Seoul');
        if ($parsed->format('Ymd') !== $drawDate) {
            throw new RuntimeException('유효하지 않은 추첨일입니다.');
        }

        return CarbonImmutable::createFromFormat('Ymd', $drawDate, 'Asia/Seoul')->startOfDay();
    }

    /** 외부 응답의 문자열 일부를 정수로 잘못 해석하지 않도록 엄격히 검증합니다. */
    private function integer(array $item, string $key): int
    {
        $value = filter_var($item[$key] ?? null, FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new RuntimeException('공식 응답 번호 필드가 올바르지 않습니다: '.$key);
        }

        return $value;
    }
}
