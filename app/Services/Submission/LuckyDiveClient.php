<?php

namespace App\Services\Submission;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class LuckyDiveClient
{
    public const PREFIX = '/api/v1/lotto/ai';

    /** 토큰이 다른 호스트로 전달되지 않도록 리디렉션과 자동 재시도를 사용하지 않습니다. */
    private function request(#[\SensitiveParameter] array $profile): PendingRequest
    {
        return Http::acceptJson()->asJson()->withToken($profile['token'])
            ->connectTimeout(5)->timeout(20)->withoutRedirecting()
            ->withOptions(['verify' => $profile['verify']]);
    }

    /** 토큰으로 인증된 프로필과 서버의 현재 제출 회차를 확인합니다. */
    public function context(#[\SensitiveParameter] array $profile): array
    {
        try {
            $response = $this->request($profile)->get($profile['base_url'].self::PREFIX.'/context');
        } catch (\Throwable) {
            throw new RuntimeException('서비스 연결에 실패했습니다. 주소·인증서·네트워크를 확인하세요.');
        }
        if ($response->status() !== 200) {
            throw new RuntimeException('서비스 인증·회차 조회 실패 (HTTP '.$response->status().').');
        }
        $data = $response->json('data');
        if (! is_array($data) || ! Str::isUuid($data['profile']['public_id'] ?? '')) {
            throw new RuntimeException('서비스 context 응답의 AI 프로필 형식을 확인하세요.');
        }
        if (! is_int($data['draw_no'] ?? null) || $data['draw_no'] < 1 || ($data['games_per_submission'] ?? null) !== 5 || ! is_int($data['remaining_submissions'] ?? null) || ! is_string($data['closes_at'] ?? null)) {
            throw new RuntimeException('서비스 context 응답 형식을 확인하세요.');
        }

        return $data;
    }

    /** 전송 예외 원문에는 토큰이 포함될 수 있으므로 상위에서 고정 오류로 처리합니다. */
    public function post(#[\SensitiveParameter] array $profile, array $payload): Response
    {
        return $this->request($profile)->post($profile['base_url'].self::PREFIX.'/submissions', $payload);
    }

    /** 성공으로 확정하기 전에 요청 회차·번호·응답 식별자를 확인합니다. */
    public function receipt(Response $response, array $payload): ?array
    {
        $d = $response->json('data');
        if ($response->status() !== 201 || ! is_array($d) || ! Str::isUuid($d['id'] ?? '') || ($d['draw_no'] ?? null) !== $payload['draw_no'] || ($d['games'] ?? null) !== $payload['games'] || ! is_string($d['algorithm_version'] ?? null) || ! is_string($d['created_at'] ?? null) || ! array_key_exists('deleted_at', $d)) {
            return null;
        }

        return array_intersect_key($d, array_flip(['id', 'draw_no', 'games', 'algorithm_version', 'created_at', 'deleted_at']));
    }
}
