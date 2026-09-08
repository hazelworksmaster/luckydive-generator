<?php

use App\Algorithms\FilteredGenerator;
use App\Algorithms\RandomGenerator;
use App\Algorithms\WeightedGenerator;

/** 생성 로직과 외부 제출 연결을 분리하고 비밀값은 환경에서만 읽습니다. */
return [
    'algorithm' => 'random-v1',
    /** 알고리즘 추가 시 버전 식별자와 구현 클래스를 등록합니다. */
    'algorithms' => [
        'weighted-v2' => WeightedGenerator::class,
        'filtered-v1' => FilteredGenerator::class,
        'random-v1' => RandomGenerator::class,
    ],
    'luckydive' => [
        'base_url' => env('LUCKYDIVE_API_BASE_URL'),
        /** 로컬 자체 서명 인증서는 공개 인증서 경로를 지정하고 검증을 끄지 않습니다. */
        'ca_bundle' => env('LUCKYDIVE_CA_BUNDLE'),
        /** 제출 대상 프로필은 각 토큰의 인증 응답에서 자동으로 확인합니다. */
        'profiles' => [
            'random' => ['algorithm' => 'random-v1', 'token' => env('LUCKYDIVE_AI_RANDOM_TOKEN')],
            'filtered' => ['algorithm' => 'filtered-v1', 'token' => env('LUCKYDIVE_AI_FILTERED_TOKEN')],
            'weighted' => ['algorithm' => 'weighted-v2', 'token' => env('LUCKYDIVE_AI_WEIGHTED_TOKEN')],
        ],
    ],
];
