<?php

/** draw weighted_v2와 같은 분포 혼합 및 특성 비중을 유지합니다. */
return [
    'recommendation_model' => [
        'version' => 'weighted_v2',
        'recent_window' => 200,
        /** 짧은 기간의 변동을 그대로 추종하지 않도록 전체 조합과 장기 이력에 더 큰 비중을 둔다. */
        'distribution_blend' => [
            'baseline' => 0.50,
            'all_draws' => 0.35,
            'recent_draws' => 0.15,
        ],
        'feature_weights' => [
            'odd_even' => 0.15,
            'sum' => 0.20,
            'low_high' => 0.10,
            'number_range' => 0.10,
            'max_consecutive_run' => 0.10,
            'ac_value' => 0.10,
            'previous_overlap' => 0.10,
            /** 끝자리 중복과 번호 쌍 동반 출현은 과적합을 줄이도록 낮은 비중으로 반영합니다. */
            'last_digit_repeat' => 0.075,
            'cooccurrence' => 0.075,
        ],
        /** 작은 표본의 극단값이 특정 조합을 과도하게 밀어내거나 끌어올리지 않게 제한한다. */
        'minimum_lift' => 0.75,
        'maximum_lift' => 1.25,
        /** 전체 814만 조합 중 매 실행에서 균등 표본으로 점수를 계산할 후보 수다. */
        'candidate_pool_size' => 100000,
        /** 준비 명령에서 가중 순위 상위 후보를 저장해 실제 추천 선택에 재사용한다. */
        'stored_pool_size' => 10000,
        /** 추천 게임끼리 같은 번호가 과도하게 겹치지 않도록 허용하는 최대 중복 수다. */
        'maximum_game_overlap' => 3,
    ],
];
