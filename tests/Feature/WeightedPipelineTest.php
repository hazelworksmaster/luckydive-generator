<?php

namespace Tests\Feature;

use App\Algorithms\Contracts\RecordsSelections;
use App\Algorithms\WeightedGenerator;
use App\Services\Filtered\PipelineState;
use App\Services\GenerateNumbers;
use App\Services\Lotto\BuildRecommendationWeightProfile;
use App\Services\Lotto\CalculateCombinationFeatures;
use App\Services\Lotto\DrawHistory;
use App\Services\Lotto\GenerateWeightedRecommendations;
use App\Services\Lotto\LottoDrawCalendar;
use App\Services\Weighted\BuildCombinations;
use App\Services\Weighted\PrepareWeighted;
use App\Services\Weighted\WeightedState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WeightedPipelineTest extends TestCase
{
    use RefreshDatabase;

    /** 실제 이력·회차 검증을 유지한 작은 통계 표본으로 계산과 저장을 검증합니다. */
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2002-12-22 12:00', 'Asia/Seoul'));
        $games = [[1, 2, 3, 4, 5, 6], [7, 8, 9, 10, 11, 12], [13, 14, 15, 16, 17, 18], [19, 20, 21, 22, 23, 24], [25, 26, 27, 28, 29, 30], [31, 32, 33, 34, 35, 36], [37, 38, 39, 40, 41, 42], [1, 8, 15, 22, 29, 36]];
        foreach ($games as $index => $numbers) {
            $row = array_combine(['number1', 'number2', 'number3', 'number4', 'number5', 'number6'], $numbers);
            DB::table('lotto_combinations')->insert(['id' => $index + 1, ...$row, ...app(CalculateCombinationFeatures::class)->calculate($numbers)]);
            if ($index < 3) {
                $history = app(DrawHistory::class);
                $history->store([$history->fromArray(['round' => $index + 1, ...$row, 'bonus' => 45, 'date' => '2002-12-'.(7 + $index * 7 < 10 ? '0' : '').(7 + $index * 7)])], 'initial_csv', true);
            }
        }
    }

    /** 다른 테스트의 회차 계산에 영향을 주지 않도록 시각을 복원합니다. */
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** 814만 행 적재 경계만 대체하고 실제 모델 계산·이력 검증·transaction을 실행합니다. */
    private function prepare(bool $apply = true): array
    {
        $state = Mockery::mock(WeightedState::class, [app(PipelineState::class), app(LottoDrawCalendar::class)])->makePartial();
        $state->shouldReceive('assertCombinations')->once();
        $this->app->instance(WeightedState::class, $state);

        return app(PrepareWeighted::class)->execute(4, 8, 5, 20260827, $apply);
    }

    /** draw와 같은 입력·seed에서 같은 후보를 재현하며 과거 당첨 조합은 제외합니다. */
    public function test_core_is_reproducible_and_excludes_winners(): void
    {
        $service = app(GenerateWeightedRecommendations::class);
        $first = $service->generate(3, 4, 8, 3, 20260827);
        $second = $service->generate(3, 4, 8, 3, 20260827);
        $this->assertSame($first->games, $second->games);
        $this->assertSame(5, $first->candidatePoolSize);
        $this->assertSame(3, $first->historicalWinningCombinationCount);
        foreach ($first->games as $index => $game) {
            $this->assertGreaterThan(3, $game['combination_id']);
            $this->assertGreaterThanOrEqual(0.75, $game['sampling_weight']);
            $this->assertLessThanOrEqual(1.25, $game['sampling_weight']);
            foreach (array_slice($first->games, $index + 1) as $other) {
                $this->assertLessThanOrEqual(3, count(array_intersect($game['numbers'], $other['numbers'])));
            }
        }
    }

    /** 미리보기는 모델·후보·실행·선택 이력을 만들지 않습니다. */
    public function test_prepare_dry_run_does_not_write(): void
    {
        $result = $this->prepare(false);
        $this->assertFalse($result['applied']);
        $this->assertSame(5, $result['entry_count']);
        foreach (['weighted_models', 'weighted_pools', 'weighted_pool_entries', 'weighted_selections', 'recommendation_runs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    /** 미리보기 후에도 모든 후보를 발급할 수 있고 실행 간 중복은 금지합니다. */
    public function test_preview_and_atomic_issue_until_exhaustion(): void
    {
        $this->prepare();
        $service = app(GenerateNumbers::class);
        $preview = $service->execute(5, 4, false, 'weighted-v2');
        $this->assertCount(5, $preview['games']);
        $this->assertDatabaseCount('weighted_selections', 0);
        $first = $service->execute(3, 4, true, 'weighted-v2');
        $second = $service->execute(2, 4, true, 'weighted-v2');
        $keys = array_map(fn ($n) => implode(',', $n), [...$first['games'], ...$second['games']]);
        $this->assertCount(5, array_unique($keys));
        $this->assertDatabaseCount('weighted_selections', 5);
        $this->assertDatabaseCount('recommendation_runs', 2);
        $this->artisan('lotto:generate', ['--algorithm' => 'weighted-v2', '--count' => 1, '--save' => true])->assertFailed();
        $this->assertDatabaseCount('recommendation_runs', 2);
    }

    /** 요청 전체 수량이 부족하면 한 게임도 기록하지 않습니다. */
    public function test_insufficient_count_does_not_partially_save(): void
    {
        $this->prepare();
        $this->artisan('lotto:generate', ['--algorithm' => 'weighted-v2', '--count' => 6, '--save' => true])->assertFailed();
        $this->assertDatabaseCount('recommendation_runs', 0);
        $this->assertDatabaseCount('weighted_selections', 0);
    }

    /** 당첨 정정은 후보를 무효화하고 새 개정 후에도 이전 회차 발급 이력을 유지합니다. */
    public function test_correction_requires_new_revision_and_preserves_selections(): void
    {
        $this->prepare();
        $saved = app(GenerateNumbers::class)->execute(1, 4, true, 'weighted-v2');
        $history = app(DrawHistory::class);
        $row = (array) DB::table('winning_numbers')->where('round', 1)->first();
        $row['bonus'] = 44;
        $history->store([$history->fromArray($row)], 'dhlottery', true);
        $this->assertSame(0, DB::table('weighted_pools')->where('ready', true)->count());
        $this->artisan('lotto:generate', ['--algorithm' => 'weighted-v2', '--count' => 1])->assertFailed();
        $this->prepare();
        $this->assertDatabaseCount('weighted_models', 2);
        $new = app(GenerateNumbers::class)->execute(4, 4, true, 'weighted-v2');
        $this->assertNotContains($saved['games'][0], $new['games']);
        $this->assertDatabaseCount('weighted_selections', 5);
    }

    /** 누락 회차·오래된 회차·설정 변경·부분 조합은 생성이나 모델 준비에 쓰지 않습니다. */
    public function test_stale_configuration_wrong_round_and_incomplete_combinations_are_rejected(): void
    {
        try {
            app(WeightedState::class)->assertCombinations();
            $this->fail('부분 조합을 허용하면 안 됩니다.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('8145060', $e->getMessage());
        }
        $this->prepare();
        $this->artisan('lotto:generate', ['--algorithm' => 'weighted-v2', '--draw-no' => 3])->assertFailed();
        config(['lotto.recommendation_model.recent_window' => 100]);
        $this->artisan('lotto:generate', ['--algorithm' => 'weighted-v2'])->assertFailed();
        DB::table('winning_numbers')->where('round', 2)->delete();
        $this->artisan('lotto:prepare-weighted', ['--dry-run' => true])->assertFailed();
    }

    /** 발급 이력 저장이 실패하면 먼저 만든 공통 실행 원장도 rollback합니다. */
    public function test_selection_failure_rolls_back_run(): void
    {
        $this->prepare();
        $real = app(WeightedGenerator::class);
        $fake = new class($real) implements RecordsSelections
        {
            /** 실제 선택 경로를 유지하고 저장 장애만 주입합니다. */
            public function __construct(private WeightedGenerator $real) {}

            /** 등록된 식별자와 일치해야 합니다. */
            public function identifier(): string
            {
                return 'weighted-v2';
            }

            /** 원본 번호 생성 계약입니다. */
            public function generate(int $count): array
            {
                return $this->real->generate($count);
            }

            /** 원본 최신 이력·후보 검증을 실행합니다. */
            public function generateContext(int $count, ?int $drawNo): array
            {
                return $this->real->generateContext($count, $drawNo);
            }

            /** 한 항목 저장 후 오류를 내 실제 rollback을 확인합니다. */
            public function recordSelections(string $runId, array $context): void
            {
                $context['metadata']['selections'] = array_slice($context['metadata']['selections'], 0, 1);
                $this->real->recordSelections($runId, $context);
                throw new RuntimeException('선택 저장 장애');
            }
        };
        $this->app->instance(WeightedGenerator::class, $fake);
        $this->artisan('lotto:generate', ['--algorithm' => 'weighted-v2', '--count' => 2, '--save' => true])->assertFailed();
        $this->assertDatabaseCount('recommendation_runs', 0);
        $this->assertDatabaseCount('weighted_selections', 0);
    }

    /** 작은 배치 적재와 재개 결과가 동일 사전식 순번·고정 특성을 갖는지 확인합니다. */
    public function test_combination_builder_resumes_and_dry_run_does_not_write(): void
    {
        DB::table('lotto_combinations')->delete();
        $builder = app(BuildCombinations::class);
        $plan = $builder->execute(false, 10);
        $this->assertSame(10, $plan['planned']);
        $this->assertDatabaseCount('lotto_combinations', 0);
        $builder->execute(true, 5);
        $builder->execute(true, 5);
        $this->assertDatabaseCount('lotto_combinations', 10);
        $this->assertDatabaseHas('lotto_combinations', ['id' => 1, 'number6' => 6, 'odd_count' => 3, 'number_sum' => 21, 'ac_value' => 0]);
        $this->assertDatabaseHas('lotto_combinations', ['id' => 10, 'number6' => 15]);
        DB::table('lotto_combinations')->where('id', 3)->delete();
        $this->expectException(RuntimeException::class);
        $builder->execute(true, 1);
    }

    /** draw 원본을 별도 로딩해 얻은 모델 지문·seed별 순서·가중치와 일치해야 합니다. */
    public function test_matches_original_draw_calculation(): void
    {
        $profile = app(BuildRecommendationWeightProfile::class)->build(3);
        $this->assertSame('09104560bcfb197a54ed243b802b5e1576ab87b5d53641fea2cad7368cc0263f', hash('sha256', json_encode($profile, JSON_THROW_ON_ERROR)));
        $result = app(GenerateWeightedRecommendations::class)->generate(3, 4, 8, 3, 20260827, null, $profile, false);
        $this->assertSame([8, 7, 6, 5], array_column($result->games, 'combination_id'));
        $this->assertSame([0.837403, 0.946455, 0.946455, 0.946455], array_column($result->games, 'sampling_weight'));
    }

    /** 명령의 옵션 전달과 기본 미저장 및 명시적 적용을 검증합니다. */
    public function test_prepare_command_and_conflicting_flags(): void
    {
        $state = Mockery::mock(WeightedState::class, [app(PipelineState::class), app(LottoDrawCalendar::class)])->makePartial();
        $state->shouldReceive('assertCombinations')->twice();
        $this->app->instance(WeightedState::class, $state);
        $options = ['--target-round' => 4, '--candidate-count' => 8, '--entry-count' => 5, '--seed' => 20260827];
        $this->artisan('lotto:prepare-weighted', $options)->assertSuccessful();
        $this->assertDatabaseCount('weighted_models', 0);
        $this->artisan('lotto:prepare-weighted', [...$options, '--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('weighted_models', 1);
        $this->artisan('lotto:prepare-weighted', ['--apply' => true, '--dry-run' => true])->assertFailed();
        $this->artisan('lotto:build-combinations', ['--apply' => true, '--dry-run' => true])->assertFailed();
        $this->artisan('lotto:generate', ['--algorithm' => 'weighted-v2', '--count' => 5, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('weighted_selections', 0);
    }

    /** 계산 도중 수집 내용이 바뀌면 계산 결과를 저장하지 않습니다. */
    public function test_history_change_during_preparation_is_rejected(): void
    {
        $state = Mockery::mock(WeightedState::class, [app(PipelineState::class), app(LottoDrawCalendar::class)])->makePartial();
        $state->shouldReceive('assertCombinations')->once();
        $this->app->instance(WeightedState::class, $state);
        try {
            app(PrepareWeighted::class)->execute(4, 8, 5, 20260827, true, function (): void {
                DB::table('winning_numbers')->where('round', 1)->update(['content_hash' => str_repeat('b', 64)]);
            });
            $this->fail('변경된 이력을 허용하면 안 됩니다.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('계산 중', $e->getMessage());
        }
        $this->assertDatabaseCount('weighted_models', 0);
        $this->assertDatabaseCount('weighted_pool_entries', 0);
    }
    /** 정정을 다시 원복해 이력 지문이 같아져도 무효화된 풀은 새 개정으로 복구합니다. */
    public function test_reverted_correction_can_rebuild_and_duplicate_prepare_is_rejected(): void
    {
        $this->prepare();
        $this->artisan('lotto:prepare-weighted', ['--candidate-count' => 8, '--entry-count' => 5, '--apply' => true])->assertFailed();
        $history = app(DrawHistory::class);
        $original = (array) DB::table('winning_numbers')->where('round', 1)->first();
        $corrected = [...$original, 'bonus' => 44];
        $history->store([$history->fromArray($corrected)], 'dhlottery', true);
        $history->store([$history->fromArray($original)], 'dhlottery', true);
        $this->prepare();
        $this->assertDatabaseCount('weighted_models', 2);
        $this->assertSame(1, DB::table('weighted_pools')->where('ready', true)->count());
        $this->artisan('lotto:generate', ['--algorithm' => 'weighted-v2', '--count' => 5])->assertSuccessful();
    }

}
