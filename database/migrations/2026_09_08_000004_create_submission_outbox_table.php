<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 토큰을 저장하지 않고 재전송할 동일 요청과 응답 영수증을 보존합니다. */
    public function up(): void
    {
        Schema::create('submission_outbox', function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->comment('AI 프로필별 외부 등록 요청 원장; 같은 UUID와 번호로 재전송');
            $t->uuid('id')->primary()->comment('서비스 요청 UUID; 재시도에도 불변');
            $t->uuid('run_id')->unique()->comment('저장된 추천 생성 실행 UUID');
            $t->foreign('run_id')->references('id')->on('recommendation_runs');
            $t->string('profile_key', 64)->comment('generator 설정의 AI 별칭');
            $t->uuid('profile_public_id')->comment('인증된 서비스 AI 프로필 UUID');
            $t->string('algorithm', 100)->comment('generator 알고리즘 식별자');
            $t->string('base_url', 255)->comment('원래 제출 대상; 재시도 시 다른 서비스 전송 금지');
            $t->unsignedInteger('draw_no')->comment('context에서 확인한 제출 회차');
            $t->json('payload')->comment('최초 요청 UUID·회차·5게임 원문; 수정 금지');
            $t->char('payload_hash', 64)->comment('요청 본문 SHA-256');
            $t->string('status', 16)->default('pending')->comment('pending/sending/unknown/rejected/submitted');
            $t->unsignedInteger('attempts')->default(0)->comment('전송 시도 횟수');
            $t->uuid('lease_id')->nullable()->comment('전송 중 프로세스의 임대 식별자');
            $t->timestamp('lease_until')->nullable()->comment('장애 시 재시도를 허용하는 임대 만료');
            $t->timestamp('retry_after')->nullable()->comment('429 응답의 최소 재시도 시각');
            $t->unsignedSmallInteger('http_status')->nullable()->comment('마지막 전송 HTTP 상태');
            $t->string('error_code', 64)->nullable()->comment('허용된 오류 코드; 원문 오류와 토큰 저장 금지');
            $t->json('receipt')->nullable()->comment('검증한 성공 응답 영수증');
            $t->timestamp('created_at')->comment('최초 요청 준비 시각');
            $t->timestamp('updated_at')->comment('마지막 상태 반영 시각');
            $t->index(['profile_key', 'draw_no', 'status']);
            $t->index(['status', 'retry_after']);
        });
    }

    /** 요청 UUID 기록을 잃으면 중복 전송 확인이 어려우므로 rollback 전 백업합니다. */
    public function down(): void
    {
        Schema::dropIfExists('submission_outbox');
    }
};
