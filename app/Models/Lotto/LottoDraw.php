<?php

namespace App\Models\Lotto;

use Illuminate\Database\Eloquent\Model;

/**
 * 저장된 로또645 회차별 당첨 결과.
 */
class LottoDraw extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'round';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $table = 'winning_numbers';

    protected $fillable = [
        'round',
        'number1',
        'number2',
        'number3',
        'number4',
        'number5',
        'number6',
        'bonus',
        'date',
    ];

    /** generator의 당첨번호를 정수와 추첨일로 읽습니다. */
    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'number1' => 'integer',
            'number2' => 'integer',
            'number3' => 'integer',
            'number4' => 'integer',
            'number5' => 'integer',
            'number6' => 'integer',
            'bonus' => 'integer',
            'date' => 'date',
        ];
    }
}
