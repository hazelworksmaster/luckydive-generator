<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 생성 결과와 알고리즘 버전을 보존하고 회차·알고리즘별 조회를 지원합니다. */
    public function up(): void
    {
        Schema::create('recommendation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('algorithm', 100);
            $table->unsignedInteger('draw_no')->nullable();
            $table->unsignedSmallInteger('game_count');
            $table->json('games');
            $table->string('status', 32)->default('generated');
            $table->timestamps();
            $table->index(['algorithm', 'created_at']);
            $table->index(['draw_no', 'created_at']);
        });
    }

    /** 저장 결과가 생긴 뒤 되돌리면 소실되므로 실제 실행 전 백업이 필요합니다. */
    public function down(): void
    {
        Schema::dropIfExists('recommendation_runs');
    }
};
