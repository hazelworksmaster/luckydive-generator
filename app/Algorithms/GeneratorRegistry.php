<?php

namespace App\Algorithms;

use App\Algorithms\Contracts\NumberGenerator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LogicException;

final class GeneratorRegistry
{
    /** 설정에 등록한 구현만 선택할 수 있도록 생성기 해석을 한 곳에 모읍니다. */
    public function __construct(private Container $container, private Repository $config) {}

    /** 사용자 입력을 클래스명으로 직접 실행하지 않고 허용 목록에서 찾습니다. */
    public function resolve(?string $algorithm = null): NumberGenerator
    {
        $identifier = $algorithm ?? $this->config->get('recommendation.algorithm');
        $algorithms = $this->config->get('recommendation.algorithms', []);
        if (! is_string($identifier) || ! isset($algorithms[$identifier])) {
            throw new InvalidArgumentException('지원하지 않는 알고리즘입니다. 사용 가능: '.implode(', ', array_keys($algorithms)));
        }

        $generator = $this->container->make($algorithms[$identifier]);
        if (! $generator instanceof NumberGenerator || $generator->identifier() !== $identifier) {
            throw new LogicException('등록된 알고리즘의 계약 또는 버전 식별자가 일치하지 않습니다.');
        }

        return $generator;
    }
}
