<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 전체 조합·모델 개정·후보·발급 이력을 generator DB에 독립 구성합니다. */
    public function up(): void
    {
        Schema::create('lotto_combinations', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->comment('전체 8145060개 조합과 고정 통계 특성; 사전식 순번');
            $t->unsignedInteger('id')->primary()->comment('1부터 연속된 전체 조합 순번');
            for ($i = 1; $i <= 6; $i++) {
                $t->unsignedTinyInteger('number'.$i)->comment('오름차순 정규번호 '.$i);
            }
            foreach (['odd_count' => '홀수 개수', 'low_count' => '22 이하 번호 개수', 'number_range' => '최대 최소 번호 차이', 'max_consecutive_run' => '최장 연속번호 개수', 'ac_value' => '서로 다른 차이 개수에서 5를 뺀 AC값'] as $column => $comment) {
                $t->unsignedTinyInteger($column)->comment($comment);
                $t->index($column);
            }
            $t->unsignedSmallInteger('number_sum')->index()->comment('정규번호 합계');
            for ($i = 1; $i <= 5; $i++) {
                $t->unsignedTinyInteger('section'.$i.'_count')->comment('10개 단위 번호 구간 '.$i.' 개수; 마지막 41~45');
            }
        });
        Schema::create('weighted_models', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->comment('당첨 이력 및 설정별 가중 모델 스냅샷; 정정 시 새 개정 추가');
            $t->id()->comment('모델 개정 ID');
            $t->unsignedInteger('target_round')->index()->comment('추천 대상 회차');
            $t->unsignedInteger('basis_round')->comment('통계 마지막 회차');
            $t->string('algorithm', 32)->comment('generator 알고리즘 weighted-v2');
            $t->char('draws_hash', 64)->comment('기준 당첨 이력 지문');
            $t->char('config_hash', 64)->comment('계산 설정 지문');
            $t->char('model_hash', 64)->comment('직렬화 모델 지문');
            $t->json('profile')->comment('weighted_v2 분포와 배율 전체 스냅샷');
            $t->timestamp('created_at')->comment('모델 계산 완료 시각');
        });
        Schema::create('weighted_pools', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->comment('모델별 후보 풀; 과거 풀과 발급 원본 보존');
            $t->id()->comment('후보 풀 ID');
            $t->foreignId('model_id')->comment('원본 가중 모델')->constrained('weighted_models');
            $t->unsignedInteger('target_round')->comment('추천 대상 회차');
            $t->boolean('ready')->default(true)->comment('사용 가능한 후보 풀 여부');
            $t->unsignedBigInteger('seed')->comment('후보 추출 재현용 seed');
            $t->unsignedInteger('candidate_count')->comment('실제 평가 후보 수');
            $t->unsignedInteger('entry_count')->comment('저장된 가중 후보 수');
            $t->unsignedInteger('historical_winner_count')->comment('제외한 역대 1등 고유 조합 수');
            $t->timestamp('created_at')->comment('후보 준비 완료 시각');
            $t->index(['target_round', 'ready', 'id']);
        });
        Schema::create('weighted_pool_entries', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->comment('가중 무작위 순위로 추출한 내부 후보');
            $t->id()->comment('후보 항목 ID');
            $t->foreignId('pool_id')->comment('소속 후보 풀')->constrained('weighted_pools');
            $t->unsignedInteger('sequence')->comment('풀 안의 연속 순번');
            $t->unsignedInteger('combination_id')->comment('전체 조합 사전식 순번');
            for ($i = 1; $i <= 6; $i++) {
                $t->unsignedTinyInteger('number'.$i)->comment('오름차순 후보번호 '.$i);
            }
            $t->decimal('sampling_weight', 12, 6)->comment('가중 무작위 추출 배율');
            $t->unique(['pool_id', 'sequence']);
            $t->unique(['pool_id', 'combination_id']);
        });
        Schema::create('weighted_selections', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->comment('저장된 가중 추천 선택 이력; 외부 서비스 제출 여부와 무관');
            $t->id()->comment('선택 ID');
            $t->uuid('run_id')->comment('공통 추천 실행 UUID');
            $t->foreign('run_id')->references('id')->on('recommendation_runs');
            $t->foreignId('entry_id')->comment('선택한 원본 후보')->constrained('weighted_pool_entries');
            $t->unsignedInteger('target_round')->comment('추천 대상 회차');
            $t->unsignedInteger('combination_id')->comment('회차 전체 중복을 막는 조합 순번');
            $t->unsignedTinyInteger('sequence')->comment('실행 안의 게임 순서');
            $t->unsignedBigInteger('selection_seed')->comment('선택 시 난수 seed');
            $t->timestamp('created_at')->comment('선택 저장 시각');
            $t->unique(['target_round', 'combination_id']);
            $t->unique(['run_id', 'sequence']);
        });
    }

    /** 실제 선택 이력은 삭제되므로 운영 rollback 전에 반드시 백업합니다. */
    public function down(): void
    {
        foreach (['weighted_selections', 'weighted_pool_entries', 'weighted_pools', 'weighted_models', 'lotto_combinations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
