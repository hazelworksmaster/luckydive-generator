<?php

namespace App\Services\Submission;

use RuntimeException;

final class SubmissionSettings
{
    /** 등록된 별칭만 해석하고 비밀값은 환경 설정에서만 읽습니다. */
    public function profile(string $key): array
    {
        $profiles = config('recommendation.luckydive.profiles', []);
        $p = $profiles[$key] ?? null;
        if (! is_array($p) || ! is_string($p['token'] ?? null) || trim($p['token']) === '') {
            throw new RuntimeException('AI 별칭과 프로필별 토큰 설정을 확인하세요.');
        }
        $base = rtrim((string) config('recommendation.luckydive.base_url'), '/');
        $url = parse_url($base);
        if (! $url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || ! empty($url['path'])) {
            throw new RuntimeException('BASE URL에는 경로·쿼리·인증정보 없는 HTTPS 기본 주소를 입력하세요.');
        }
        $ca = config('recommendation.luckydive.ca_bundle');
        if ($ca && (! is_string($ca) || ! is_readable($ca))) {
            throw new RuntimeException('설정한 공개 인증서 파일을 읽을 수 없습니다.');
        }

        return [...$p, 'key' => $key, 'base_url' => $base, 'verify' => $ca ?: true];
    }
}
