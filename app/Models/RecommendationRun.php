<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 생성 결과의 로컬 원장입니다. LuckyDive 공개 제출 원본과는 구분합니다. */
class RecommendationRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'algorithm', 'draw_no', 'game_count', 'games', 'status', 'metadata'];

    /** 번호와 게임 수는 읽을 때에도 원래 자료형을 유지합니다. */
    protected function casts(): array
    {
        return ['metadata' => 'array', 'games' => 'array', 'game_count' => 'integer', 'draw_no' => 'integer'];
    }
}
