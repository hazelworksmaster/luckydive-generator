<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 공식 이력과 최신 후보 한 벌을 분리하고 모든 새 컬럼에 업무 설명을 기록합니다. */
    public function up(): void
    {
        Schema::create('winning_numbers', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->comment('공식 당첨번호 이력: 직접 수집 및 최초 CSV 반입');
            $t->unsignedInteger('round')->primary()->comment('공식 추첨 회차');
            for ($i = 1; $i <= 6; $i++) {
                $t->unsignedTinyInteger('number'.$i)->comment('오름차순 정규번호 '.$i);
            }
            $t->unsignedTinyInteger('bonus')->comment('보너스 번호');
            $t->date('date')->comment('한국 기준 추첨일');
            $t->string('source', 32)->comment('수집 출처 dhlottery 또는 initial_csv');
            $t->char('content_hash', 64)->comment('회차·번호·추첨일 SHA-256');
            $t->timestamp('updated_at')->comment('마지막 내용 반영 시각');
        });
        foreach (['filtered_base_candidates' => '고정 필터 1~4 통과 후보', 'filter5_numbers' => '최신 역대 당첨번호 및 이동 조합 제외 후보', 'filter6_numbers' => '최신 차이·합계 통계 조건까지 통과한 최종 후보'] as $name => $comment) {
            Schema::create($name, function (Blueprint $t) use ($name, $comment): void {
                $t->engine = 'InnoDB';
                $t->comment($comment.'; 회차별 누적 보관하지 않음');
                $t->unsignedInteger('id')->primary()->comment($name === 'filter6_numbers' ? '균등 무작위 선택용 1부터 연속된 순번' : '전체 조합 사전식 순회 순번');
                for ($i = 1; $i <= 6; $i++) {
                    $t->unsignedTinyInteger('number'.$i)->comment('오름차순 후보번호 '.$i);
                }
                $t->unsignedTinyInteger('number_range')->comment('최대 번호와 최소 번호 차이');
                $t->unsignedSmallInteger('number_sum')->comment('정규번호 6개 합계');
                $t->index(['number_range', 'number_sum'], $name.'_stats_idx');
            });
        }
        Schema::create('filtered_pipeline_state', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->comment('최신 후보의 준비 상태 및 동시 실행 직렬화용 단일 행');
            $t->unsignedTinyInteger('id')->primary()->comment('항상 1인 공용 잠금 행');
            $t->string('base_version', 32)->nullable()->comment('고정 필터 규칙 버전');
            $t->unsignedInteger('base_count')->default(0)->comment('완성된 고정 후보 건수');
            $t->timestamp('base_prepared_at')->nullable()->comment('고정 후보 준비 완료 시각');
            $t->boolean('ready')->default(false)->comment('최신 필터 후보 사용 가능 여부');
            $t->unsignedInteger('basis_round')->nullable()->comment('통계에 포함한 마지막 당첨 회차');
            $t->char('draws_hash', 64)->nullable()->comment('전체 기준 당첨 이력 지문');
            $t->string('algorithm', 32)->nullable()->comment('최종 후보 알고리즘 버전');
            $t->unsignedInteger('filter5_count')->default(0)->comment('최신 filter5 건수');
            $t->unsignedInteger('filter6_count')->default(0)->comment('최신 filter6 건수');
            $t->json('statistics')->nullable()->comment('상위 차이 10개와 합계 20개');
            $t->timestamp('prepared_at')->nullable()->comment('최종 후보 준비 완료 시각');
        });
        DB::table('filtered_pipeline_state')->insert(['id' => 1]);
        Schema::table('recommendation_runs', function (Blueprint $t): void {
            $t->json('metadata')->nullable()->comment('사용한 후보 기준 회차·지문·규칙 스냅샷');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE recommendation_runs COMMENT = '알고리즘별 실제 추천 생성 결과 원장'");
        }
    }

    /** 후보는 재생성 가능하지만 당첨 이력·메타데이터는 rollback 전에 백업해야 합니다. */
    public function down(): void
    {
        Schema::table('recommendation_runs', fn (Blueprint $t) => $t->dropColumn('metadata'));
        foreach (['filter6_numbers', 'filter5_numbers', 'filtered_base_candidates', 'filtered_pipeline_state', 'winning_numbers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
