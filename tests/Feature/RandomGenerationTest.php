<?php

namespace Tests\Feature;

use App\Algorithms\Contracts\NumberGenerator;
use App\Algorithms\RandomGenerator;
use App\Models\RecommendationRun;
use App\Services\GenerateNumbers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RandomGenerationTest extends TestCase
{
    use RefreshDatabase;

    /** 최대 수량에서도 번호 범위·오름차순·게임 내외 중복 금지를 보장합니다. */
    public function test_full_batch_has_valid_unique_combinations(): void
    {
        $games = (new RandomGenerator)->generate(100);
        $this->assertCount(100, $games);
        $this->assertCount(100, array_unique(array_map('serialize', $games)));
        foreach ($games as $game) {
            $this->assertCount(6, $game);
            $this->assertCount(6, array_unique($game));
            $sorted = $game;
            sort($sorted);
            $this->assertSame($sorted, $game);
            foreach ($game as $number) {
                $this->assertIsInt($number);
                $this->assertGreaterThanOrEqual(1, $number);
                $this->assertLessThanOrEqual(45, $number);
            }
        }
    }

    /** 기본과 dry-run 명령은 실제 결과 원장을 만들지 않습니다. */
    public function test_preview_does_not_save(): void
    {
        $this->artisan('lotto:generate')->assertSuccessful();
        $this->artisan('lotto:generate', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('recommendation_runs', 0);
    }

    /** 저장을 요청한 결과는 출력과 동일한 번호·버전·회차를 보존합니다. */
    public function test_saved_generation_preserves_result(): void
    {
        $result = $this->app->make(GenerateNumbers::class)->execute(5, 1241, true);
        $run = RecommendationRun::query()->findOrFail($result['id']);
        $this->assertSame($result['games'], $run->games);
        $this->assertSame('random-v1', $run->algorithm);
        $this->assertSame(1241, $run->draw_no);
        $this->artisan('lotto:generate', ['--count' => 1, '--save' => true])->assertSuccessful();
        $this->assertDatabaseCount('recommendation_runs', 2);
    }

    /** 잘못된 입력은 DB 저장 없이 실패합니다. */
    public function test_invalid_inputs_are_rejected(): void
    {
        foreach (['0', '101', '-1', '2.5', 'abc'] as $count) {
            $this->artisan('lotto:generate', ['--count' => $count, '--save' => true])->assertFailed();
        }
        $this->artisan('lotto:generate', ['--draw-no' => 0])->assertFailed();
        $this->artisan('lotto:generate', ['--dry-run' => true, '--save' => true])->assertFailed();
        $this->assertDatabaseCount('recommendation_runs', 0);
    }

    /** 명시한 알고리즘을 저장하고 알 수 없는 이름은 대체 실행 없이 거부합니다. */
    public function test_explicit_algorithm_and_unknown_algorithm(): void
    {
        $this->artisan('lotto:generate', ['--algorithm' => 'random-v1', '--save' => true])->assertSuccessful();
        $this->assertDatabaseHas('recommendation_runs', ['algorithm' => 'random-v1']);
        $this->artisan('lotto:generate', ['--algorithm' => 'unknown-v1', '--save' => true])
            ->expectsOutputToContain('지원하지 않는 알고리즘')->assertFailed();
        $this->assertDatabaseCount('recommendation_runs', 1);
    }

    /** 추가 등록한 구현으로 실제 분기하며 기본 설정과 명시적 선택을 구분합니다. */
    public function test_registry_selects_another_registered_algorithm(): void
    {
        $stub = new class implements NumberGenerator
        {
            /** 테스트용 구현 식별자입니다. */
            public function identifier(): string
            {
                return 'fixture-v1';
            }

            /** 선택된 구현 호출을 고정 결과로 검증합니다. */
            public function generate(int $count): array
            {
                return [[1, 2, 3, 4, 5, 6]];
            }
        };
        $this->app->instance(get_class($stub), $stub);
        config(['recommendation.algorithms.fixture-v1' => get_class($stub), 'recommendation.algorithm' => 'fixture-v1']);
        $service = $this->app->make(GenerateNumbers::class);
        $result = $service->execute(1, null, true);
        $this->assertSame('fixture-v1', $result['algorithm']);
        $this->assertSame([[1, 2, 3, 4, 5, 6]], $result['games']);
        $explicit = $service->execute(1, null, false, 'random-v1');
        $this->assertSame('random-v1', $explicit['algorithm']);
        $this->assertDatabaseHas('recommendation_runs', ['algorithm' => 'fixture-v1']);
    }
}
