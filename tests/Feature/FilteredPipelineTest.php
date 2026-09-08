<?php

namespace Tests\Feature;

use App\Services\Filtered\FilterRules;
use App\Services\Filtered\PrepareFilteredCandidates;
use App\Services\GenerateNumbers;
use App\Services\Lotto\CollectDraws;
use App\Services\Lotto\DhlotteryLottoDrawClient;
use App\Services\Lotto\DrawHistory;
use App\Services\Lotto\LottoDrawCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class FilteredPipelineTest extends TestCase
{
    use RefreshDatabase;

    /** 작은 고정 이력으로 최신 회차 정책을 결정적으로 검증합니다. */
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2002-12-08 12:00', 'Asia/Seoul'));
        Http::preventStrayRequests();
    }

    /** 테스트 시각이 다른 테스트에 전파되지 않도록 복원합니다. */
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** 공식 1회차 초기 이력 형식입니다. */
    private function draw(): array
    {
        return ['round' => 1, 'number1' => 10, 'number2' => 23, 'number3' => 29, 'number4' => 33, 'number5' => 37, 'number6' => 40, 'bonus' => 16, 'date' => '2002-12-07'];
    }

    /** 운영의 전체 후보 적재 대신 조건별 소수 후보로 필터 결과를 확인합니다. */
    private function seedCandidates(): void
    {
        $history = $this->app->make(DrawHistory::class);
        $history->store([$history->fromArray($this->draw())], 'initial_csv', true);
        $rules = new FilterRules;
        $games = [[10, 23, 29, 33, 37, 40], [11, 24, 30, 34, 38, 41], [8, 25, 29, 35, 37, 38], [9, 22, 30, 34, 38, 39], [1, 5, 10, 18, 26, 32]];
        foreach ($games as $id => $n) {
            DB::table('filtered_base_candidates')->insert($rules->row($id + 1, $n));
        }
        DB::table('filtered_pipeline_state')->where('id', 1)->update(['base_version' => 'base-v1', 'base_count' => 5, 'base_prepared_at' => now()]);
    }

    /** 사용자가 지정한 네 가지 고정 규칙과 이동 제외의 경계를 확인합니다. */
    public function test_fixed_rules_and_offsets(): void
    {
        $rules = new FilterRules;
        $this->assertSame(1, $rules->rejection([1, 3, 5, 7, 9, 11]));
        $this->assertSame(1, $rules->rejection([2, 4, 6, 8, 10, 12]));
        $this->assertSame(2, $rules->rejection([3, 9, 15, 24, 33, 42]));
        $this->assertSame(3, $rules->rejection([1, 2, 3, 15, 30, 40]));
        $this->assertSame(3, $rules->rejection([1, 8, 22, 41, 42, 43]));
        $this->assertSame(4, $rules->rejection([1, 5, 10, 15, 20, 45]));
        $this->assertSame(0, $rules->rejection([1, 5, 10, 15, 20, 26]));
        $this->assertSame(0, $rules->rejection([1, 5, 10, 15, 20, 44]));
        $set = $rules->exclusions([$this->draw()]);
        $this->assertCount(15, $set);
        $this->assertArrayHasKey('10,23,29,33,37,40', $set);
        $this->assertArrayHasKey('1,14,20,24,28,31', $set);
        $this->assertArrayHasKey('15,28,34,38,42,45', $set);
    }

    /** filter5 후 통계 두 조건의 교집합만 filter6에 남고 저장에 기준이 보존됩니다. */
    public function test_prepare_generate_and_metadata(): void
    {
        $this->seedCandidates();
        $service = $this->app->make(PrepareFilteredCandidates::class);
        $preview = $service->execute(false);
        $this->assertSame(3, $preview['filter5_count']);
        $this->assertSame(2, $preview['filter6_count']);
        $this->assertDatabaseCount('filter5_numbers', 0);
        $actual = $service->execute(true);
        $this->assertSame($preview['draws_hash'], $actual['draws_hash']);
        $this->assertDatabaseCount('filter5_numbers', 3);
        $this->assertDatabaseCount('filter6_numbers', 2);
        $result = $this->app->make(GenerateNumbers::class)->execute(2, 2, true, 'filtered-v1');
        $this->assertSame(2, $result['draw_no']);
        $this->assertSame(1, $result['metadata']['basis_round']);
        $this->assertSame($actual['draws_hash'], $result['metadata']['draws_hash']);
        $this->assertCount(2, array_unique(array_map('serialize', $result['games'])));
        $this->assertDatabaseHas('recommendation_runs', ['algorithm' => 'filtered-v1', 'draw_no' => 2]);
        $this->artisan('lotto:generate', ['--algorithm' => 'filtered-v1', '--count' => 1, '--dry-run' => true])->assertSuccessful();
        $this->artisan('lotto:generate', ['--algorithm' => 'filtered-v1', '--count' => 3])->assertFailed();
        $this->artisan('lotto:generate', ['--algorithm' => 'filtered-v1', '--draw-no' => 3])->assertFailed();
        $this->assertDatabaseCount('recommendation_runs', 1);
    }

    /** 정정은 후보를 무효화하고 내용이 같을 때는 준비 상태를 유지합니다. */
    public function test_correction_invalidates_candidates(): void
    {
        $this->seedCandidates();
        $this->app->make(PrepareFilteredCandidates::class)->execute(true);
        $history = $this->app->make(DrawHistory::class);
        $same = $history->store([$history->fromArray($this->draw())], 'dhlottery', true);
        $this->assertSame(1, $same['unchanged']);
        $this->assertTrue((bool) DB::table('filtered_pipeline_state')->value('ready'));
        $row = $this->draw();
        $row['bonus'] = 17;
        $preview = $history->store([$history->fromArray($row)], 'dhlottery', false);
        $this->assertSame(1, $preview['updated']);
        $this->assertTrue((bool) DB::table('filtered_pipeline_state')->value('ready'));
        $history->store([$history->fromArray($row)], 'dhlottery', true);
        $this->assertFalse((bool) DB::table('filtered_pipeline_state')->value('ready'));
        $this->artisan('lotto:generate', ['--algorithm' => 'filtered-v1', '--count' => 1])->assertFailed();
    }

    /** 실패한 후보 교체는 기존 결과와 준비 기록을 rollback합니다. */
    public function test_failed_prepare_preserves_previous_tables(): void
    {
        $this->seedCandidates();
        $service = $this->app->make(PrepareFilteredCandidates::class);
        $service->execute(true);
        $before = DB::table('filter6_numbers')->get()->toJson();
        DB::table('filtered_pipeline_state')->where('id', 1)->update(['base_count' => 999]);
        try {
            $service->execute(true);
            $this->fail('오류가 필요합니다.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('건수', $e->getMessage());
        }
        $this->assertSame($before, DB::table('filter6_numbers')->get()->toJson());
        $this->assertDatabaseCount('filter5_numbers', 3);
    }

    /** 최신 회차가 발표됐는데 누락된 경우 기존 후보로 대신 생성하지 않습니다. */
    public function test_stale_history_is_rejected(): void
    {
        $this->seedCandidates();
        $this->app->make(PrepareFilteredCandidates::class)->execute(true);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2002-12-15 12:00', 'Asia/Seoul'));
        $this->artisan('lotto:generate', ['--algorithm' => 'filtered-v1', '--count' => 1])->assertFailed();
    }

    /** 공식 응답의 정규화와 저장 없는 수집, 멱등 반영을 확인합니다. */
    public function test_official_collection_and_malformed_response(): void
    {
        $item = ['ltEpsd' => 1, 'tm1WnNo' => 10, 'tm2WnNo' => 23, 'tm3WnNo' => 29, 'tm4WnNo' => 33, 'tm5WnNo' => 37, 'tm6WnNo' => 40, 'bnsWnNo' => 16, 'ltRflYmd' => '20021207'];
        Http::fake(['*' => Http::sequence()->push(['data' => ['list' => [$item]]])->push(['data' => ['list' => [$item]]])->push(['data' => ['list' => [$item]]])->push(['unexpected' => true])]);
        $collector = $this->app->make(CollectDraws::class);
        $this->assertSame(1, $collector->execute(1, 1, false)['created']);
        $this->assertDatabaseCount('winning_numbers', 0);
        $collector->execute(1, 1, true);
        $this->assertDatabaseCount('winning_numbers', 1);
        $this->assertSame(1, $collector->execute(1, 1, true)['unchanged']);
        $this->expectException(RuntimeException::class);
        $this->app->make(DhlotteryLottoDrawClient::class)->fetch(1);
    }

    /** CSV 파일은 모두 검증 후 저장하며 dry-run과 중복 회차 실패는 DB를 변경하지 않습니다. */
    public function test_csv_import_is_atomic(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'draw-test-');
        try {
            $csv = "round,number1,number2,number3,number4,number5,number6,bonus,date\n1,10,23,29,33,37,40,16,2002-12-07\n";
            file_put_contents($path, $csv);
            $this->artisan('lotto:import-draws', ['file' => $path, '--dry-run' => true])->assertSuccessful();
            $this->assertDatabaseCount('winning_numbers', 0);
            $this->artisan('lotto:import-draws', ['file' => $path, '--apply' => true])->assertSuccessful();
            $this->assertDatabaseCount('winning_numbers', 1);
            file_put_contents($path, $csv."1,10,23,29,33,37,40,17,2002-12-07\n");
            $this->artisan('lotto:import-draws', ['file' => $path, '--apply' => true])->assertFailed();
            $this->assertDatabaseHas('winning_numbers', ['bonus' => 16]);
        } finally {
            unlink($path);
        }
    }

    /** 경계 시각과 동률 순서를 고정해 기존 draw 계산과 일치시킵니다. */
    public function test_calendar_boundary_and_statistics_ties(): void
    {
        $calendar = new LottoDrawCalendar;
        $this->assertSame(1, $calendar->latestAnnouncedDrawNo(CarbonImmutable::parse('2002-12-14 20:44:59', 'Asia/Seoul')));
        $this->assertSame(2, $calendar->latestAnnouncedDrawNo(CarbonImmutable::parse('2002-12-14 20:45:00', 'Asia/Seoul')));
        $rules = new FilterRules;
        $stats = $rules->statistics([$rules->row(1, [1, 5, 10, 18, 26, 32]), $rules->row(2, [10, 23, 29, 33, 37, 40])]);
        $this->assertSame([31, 30], $stats['diffs']);
        $this->assertSame([172, 92], $stats['sums']);
    }

    /** 날짜·중복 번호가 틀린 원천 데이터는 DB 반영 전에 거부합니다. */
    public function test_invalid_draw_dates_and_numbers_are_rejected(): void
    {
        $history = $this->app->make(DrawHistory::class);
        foreach ([['date' => '2002-12-08'], ['number2' => 10], ['bonus' => 10]] as $patch) {
            try {
                $history->fromArray([...$this->draw(), ...$patch]);
                $this->fail('검증 오류가 필요합니다.');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertDatabaseCount('winning_numbers',0);
    }
}
