<?php

namespace Tests\Feature;

use App\Services\Submission\SubmitRecommendations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubmissionTest extends TestCase
{
    use RefreshDatabase;

    private string $profileId = '11111111-1111-4111-8111-111111111111';

    /** 실제 토큰과 네트워크를 사용하지 않는 고정 프로필을 구성합니다. */
    protected function setUp(): void
    {
        parent::setUp();
        config(['recommendation.luckydive.base_url' => 'https://service.test', 'recommendation.luckydive.ca_bundle' => null, 'recommendation.luckydive.profiles.random' => ['algorithm' => 'random-v1', 'token' => 'private-test-token']]);
        Http::preventStrayRequests();
    }

    /** 서버의 현재 등록 문맥을 재현합니다. */
    private function context(int $remaining = 2, int $round = 1241): array
    {
        return ['data' => ['profile' => ['public_id' => $this->profileId, 'name' => '테스트 AI', 'algorithm_version' => 'server-v1'], 'draw_no' => $round, 'closes_at' => '2099-01-01T20:00:00+09:00', 'games_per_submission' => 5, 'remaining_submissions' => $remaining]];
    }

    /** 서버가 요청 번호를 그대로 등록한 영수증을 돌려줍니다. */
    private function receipt(Request $request): array
    {
        return ['data' => ['id' => '22222222-2222-4222-8222-222222222222', 'draw_no' => $request['draw_no'], 'games' => $request['games'], 'algorithm_version' => 'server-v1', 'created_at' => '2026-09-08 12:00:00', 'deleted_at' => null]];
    }

    /** 미리보기는 GET만 호출하고 생성·원장·POST를 실행하지 않습니다. */
    public function test_preview_does_not_generate_or_post(): void
    {
        Http::fake(['*/context' => Http::response($this->context())]);
        $this->artisan('lotto:submit', ['--profile' => 'random', '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('submission_outbox', 0);
        $this->assertDatabaseCount('recommendation_runs', 0);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->hasHeader('Authorization', 'Bearer private-test-token'));
    }

    /** 번호·원장을 먼저 확정하며 성공한 UUID는 다시 전송하지 않습니다. */
    public function test_submit_and_replay_preserve_one_run(): void
    {
        Http::fake(fn (Request $r) => Http::response($r->method() === 'GET' ? $this->context() : $this->receipt($r), $r->method() === 'GET' ? 200 : 201));
        $id = (string) Str::uuid();
        $service = app(SubmitRecommendations::class);
        $first = $service->execute('random', $id, true);
        $this->assertSame('submitted', $first['status']);
        $this->assertCount(5, $first['payload']['games']);
        $this->assertSame($this->profileId, DB::table('submission_outbox')->value('profile_public_id'));
        $this->assertSame($first, $service->execute('random', $id, true));
        $this->assertDatabaseCount('recommendation_runs', 1);
        $this->assertDatabaseCount('submission_outbox', 1);
        Http::assertSentCount(2);
        $this->assertStringNotContainsString('private-test-token', json_encode((array) DB::table('submission_outbox')->first()));
    }

    /** 응답 유실 후 회차가 바뀌고 한도가 없어도 최초 요청을 그대로 재전송합니다. */
    public function test_timeout_retry_uses_original_payload_after_round_change_and_token_rotation(): void
    {
        $sent = null;
        Http::fake(function (Request $r) use (&$sent) {
            if ($r->method() === 'GET') {
                return Http::response($this->context());
            }
            $sent = $r->data();
            throw new ConnectionException('응답 유실 private-test-token');
        });
        $id = (string) Str::uuid();
        $service = app(SubmitRecommendations::class);
        $first = $service->execute('random', $id, true);
        $this->assertSame('unknown', $first['status']);
        config(['recommendation.luckydive.profiles.random.token' => 'rotated-test-token']);
        /** 이전 실패용 HTTP 대역을 제거하고 복구된 서버 응답으로 교체합니다. */
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) use ($sent) {
            if ($r->method() === 'GET') {
                return Http::response($this->context(0, 1242));
            }
            $this->assertSame($sent, $r->data());

            return Http::response($this->receipt($r), 201, ['Idempotency-Replayed' => 'true']);
        });
        $second = $service->execute('random', $id, true);
        $this->assertSame('submitted', $second['status']);
        $this->assertSame(2, $second['attempts']);
        $this->assertDatabaseCount('recommendation_runs', 1);
    }

    /** 준비만 한 번호를 검토한 뒤 동일 UUID로 실제 전송할 수 있습니다. */
    public function test_prepare_only_and_mysql_json_key_reordering(): void
    {
        Http::fake(fn (Request $r) => Http::response($r->method() === 'GET' ? $this->context() : $this->receipt($r), $r->method() === 'GET' ? 200 : 201));
        $id = (string) Str::uuid();
        $service = app(SubmitRecommendations::class);
        $first = $service->execute('random', $id, true, true);
        $this->assertSame('pending', $first['status']);
        Http::assertSentCount(1);
        $payload = $first['payload'];
        ksort($payload);
        DB::table('submission_outbox')->where('id', $id)->update(['payload' => json_encode($payload)]);
        $second = $service->execute('random', $id, true);
        $this->assertSame('submitted', $second['status']);
        $this->assertSame($first['payload']['games'], $second['payload']['games']);
    }

    /** 주소 변경은 조회 전에, 토큰의 AI 변경은 인증 조회 후 실제 제출 전에 차단합니다. */
    public function test_destination_change_and_wrong_token_profile_are_blocked(): void
    {
        Http::fake(['*/context' => Http::response($this->context())]);
        $service = app(SubmitRecommendations::class);
        $id = (string) Str::uuid();
        $service->execute('random', $id, true, true);
        config(['recommendation.luckydive.base_url' => 'https://different.test']);
        $this->artisan('lotto:submit', ['--profile' => 'random', '--request-id' => $id, '--apply' => true])->assertFailed();
        Http::assertSentCount(1);
        config(['recommendation.luckydive.base_url' => 'https://service.test', 'recommendation.luckydive.profiles.random.token' => 'another-profile-token']);
        $this->profileId = (string) Str::uuid();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/context' => Http::response($this->context())]);
        $this->artisan('lotto:submit', ['--profile' => 'random', '--request-id' => $id, '--apply' => true])->assertFailed();
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
        $this->assertDatabaseCount('recommendation_runs', 1);
    }

    /** HTTP·전체 경로 입력·마감·한도 소진은 실제 등록 전에 거부합니다. */
    public function test_invalid_base_url_and_limits_are_rejected(): void
    {
        Http::fake(['*/context' => Http::response($this->context(0))]);
        foreach (['http://service.test', 'https://service.test/api', 'https://user:pass@service.test'] as $url) {
            config(['recommendation.luckydive.base_url' => $url]);
            $this->artisan('lotto:submit', ['--profile' => 'random', '--apply' => true])->assertFailed();
        }
        Http::assertNothingSent();
        config(['recommendation.luckydive.base_url' => 'https://service.test']);
        $this->artisan('lotto:submit', ['--profile' => 'random', '--apply' => true])->assertFailed();
        $this->assertDatabaseCount('recommendation_runs', 0);
    }

    /** 호출 제한의 재시도 대기와 활성 임대 동안 중복 전송 차단을 검증합니다. */
    public function test_rate_limit_and_lease_block_repeat_dispatch(): void
    {
        Http::fake(fn (Request $r) => $r->method() === 'GET' ? Http::response($this->context()) : Http::response(['error' => ['code' => 'rate_limited', 'message' => 'private-test-token']], 429, ['Retry-After' => '120']));
        $id = (string) Str::uuid();
        $service = app(SubmitRecommendations::class);
        $result = $service->execute('random', $id, true);
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('rate_limited', $result['error_code']);
        $this->artisan('lotto:submit', ['--profile' => 'random', '--request-id' => $id, '--apply' => true])->assertFailed();
        DB::table('submission_outbox')->where('id', $id)->update(['retry_after' => null, 'lease_until' => now()->addMinute(), 'lease_id' => (string) Str::uuid(), 'status' => 'sending']);
        $this->artisan('lotto:submit', ['--profile' => 'random', '--request-id' => $id, '--apply' => true])->assertFailed();
        $this->assertSame(1, DB::table('submission_outbox')->value('attempts'));
        $this->assertStringNotContainsString('private-test-token', json_encode((array) DB::table('submission_outbox')->first()));
    }

    /** 본문 변조와 성공 응답의 번호 불일치를 성공으로 확정하지 않습니다. */
    public function test_payload_tampering_and_malformed_success(): void
    {
        Http::fake(fn (Request $r) => Http::response($r->method() === 'GET' ? $this->context() : ['data' => ['id' => (string) Str::uuid(), 'games' => []]], $r->method() === 'GET' ? 200 : 201));
        $id = (string) Str::uuid();
        $result = app(SubmitRecommendations::class)->execute('random', $id, true);
        $this->assertSame('unknown', $result['status']);
        DB::table('submission_outbox')->where('id', $id)->update(['payload_hash' => str_repeat('0', 64)]);
        $this->artisan('lotto:submit', ['--profile' => 'random', '--request-id' => $id, '--apply' => true])->assertFailed();
        $this->assertSame(1, DB::table('submission_outbox')->value('attempts'));
    }
    /** 요청 원장 저장 실패 시 함께 생성한 실행 이력도 남지 않아야 합니다. */
    public function test_outbox_failure_rolls_back_generation_before_post(): void
    {
        Http::fake(['*/context' => Http::response($this->context())]);
        DB::listen(function ($event): void {
            if (str_starts_with($event->sql, 'insert into "submission_outbox"')) {
                throw new RuntimeException('원장 저장 장애');
            }
        });
        $this->artisan('lotto:submit', ['--profile' => 'random', '--apply' => true])->assertFailed();
        $this->assertDatabaseCount('recommendation_runs', 0);
        $this->assertDatabaseCount('submission_outbox', 0);
        Http::assertSentCount(1);
    }

    /** 프로세스가 응답 확정 전에 종료돼도 임대 만료 후 같은 번호로 재개합니다. */
    public function test_expired_sending_lease_can_resume_without_new_generation(): void
    {
        Http::fake(fn (Request $r) => Http::response($r->method() === 'GET' ? $this->context() : $this->receipt($r), $r->method() === 'GET' ? 200 : 201));
        $id = (string) Str::uuid();
        $service = app(SubmitRecommendations::class);
        $prepared = $service->execute('random', $id, true, true);
        DB::table('submission_outbox')->where('id', $id)->update(['status' => 'sending', 'lease_id' => (string) Str::uuid(), 'lease_until' => now()->subMinute(), 'attempts' => 1]);
        $result = $service->execute('random', $id, true);
        $this->assertSame('submitted', $result['status']);
        $this->assertSame(2, $result['attempts']);
        $this->assertSame($prepared['payload']['games'], $result['payload']['games']);
        $this->assertDatabaseCount('recommendation_runs', 1);
    }

}
