<?php

namespace App\Services\Submission;

use App\Services\Filtered\PipelineState;
use App\Services\GenerateNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class SubmitRecommendations
{
    /** 생성·원장 준비와 네트워크 전송을 분리해 DB 잠금을 HTTP 대기 동안 유지하지 않습니다. */
    public function __construct(private SubmissionSettings $settings, private LuckyDiveClient $client, private GenerateNumbers $generator, private PipelineState $state) {}

    /** 기본은 전송 계획이며 apply만 생성 결과·요청 원장을 저장하고 등록합니다. */
    public function execute(string $key, string $id, bool $apply, bool $prepareOnly = false): array
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('요청 ID는 UUID여야 합니다.');
        }
        $id = strtolower($id);
        $profile = $this->settings->profile($key);
        $row = Schema::hasTable('submission_outbox') ? DB::table('submission_outbox')->where('id', $id)->first() : null;
        if ($row !== null) {
            $this->assertDestination($row, $profile);
            if (! $apply || $row->status === 'submitted') {
                return $this->result($row);
            }
        }
        $context = $this->client->context($profile);
        if ($row === null) {
            if ($context['remaining_submissions'] < 1 || CarbonImmutable::parse($context['closes_at'])->lessThanOrEqualTo(CarbonImmutable::now())) {
                throw new RuntimeException('현재 회차의 남은 제출 한도 또는 마감을 확인하세요.');
            }
            if (! $apply) {
                return ['request_id' => $id, 'status' => 'preview', 'profile' => $key, 'profile_public_id' => $profile['public_id'], 'profile_name' => $context['profile']['name'] ?? '', 'algorithm' => $profile['algorithm'], 'draw_no' => $context['draw_no'], 'game_count' => 5, 'remaining_submissions' => $context['remaining_submissions'], 'endpoint' => $profile['base_url'].LuckyDiveClient::PREFIX.'/submissions', 'saved' => false];
            }
            if (! Schema::hasTable('submission_outbox')) {
                throw new RuntimeException('등록 요청 원장 migration이 필요합니다.');
            }
            $row = DB::transaction(function () use ($profile, $context, $id): object {
                $this->state->lock();
                $existing = DB::table('submission_outbox')->where('id', $id)->lockForUpdate()->first();
                if ($existing !== null) {
                    $this->assertDestination($existing, $profile);

                    return $existing;
                }
                $run = $this->generator->execute(5, $context['draw_no'], true, $profile['algorithm']);
                $payload = json_encode(['request_id' => $id, 'draw_no' => $context['draw_no'], 'games' => $run['games']], JSON_THROW_ON_ERROR);
                DB::table('submission_outbox')->insert(['id' => $id, 'run_id' => $run['id'], 'profile_key' => $profile['key'], 'profile_public_id' => $profile['public_id'], 'algorithm' => $profile['algorithm'], 'base_url' => $profile['base_url'], 'draw_no' => $context['draw_no'], 'payload' => $payload, 'payload_hash' => hash('sha256', $payload), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

                return DB::table('submission_outbox')->where('id', $id)->first();
            });
        }
        if ($prepareOnly) {
            return $this->result($row);
        }
        $lease = (string) Str::uuid();
        $row = DB::transaction(function () use ($id, $lease, $profile): object {
            $r = DB::table('submission_outbox')->where('id', $id)->lockForUpdate()->first();
            $this->assertDestination($r, $profile);
            if ($r->status === 'submitted') {
                return $r;
            }
            if ($r->lease_until && CarbonImmutable::parse($r->lease_until)->isFuture()) {
                throw new RuntimeException('다른 프로세스가 전송 중입니다. 임대 만료 후 같은 UUID로 재시도하세요.');
            }
            if ($r->retry_after && CarbonImmutable::parse($r->retry_after)->isFuture()) {
                throw new RuntimeException('서비스 호출 제한 대기 중입니다. retry_after 이후 재시도하세요.');
            }
            DB::table('submission_outbox')->where('id', $id)->update(['status' => 'sending', 'lease_id' => $lease, 'lease_until' => now()->addMinutes(2), 'attempts' => $r->attempts + 1, 'updated_at' => now()]);

            return $r;
        });
        if ($row->status === 'submitted') {
            return $this->result($row);
        }
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $httpStatus = null;
        $receipt = null;
        $code = 'connection_failed';
        $status = 'unknown';
        $retryAfter = null;
        try {
            $response = $this->client->post($profile, $payload);
            $httpStatus = $response->status();
            $receipt = $this->client->receipt($response, $payload);
            if ($receipt !== null) {
                $status = 'submitted';
                $code = null;
            } else {
                $status = $httpStatus >= 400 && $httpStatus < 500 ? 'rejected' : 'unknown';
                $candidate = $response->json('error.code');
                $code = in_array($candidate, ['unauthenticated', 'round_not_open', 'submission_limit', 'duplicate_game', 'idempotency_conflict', 'payload_too_large', 'validation_failed', 'rate_limited'], true) ? $candidate : 'unexpected_response';
                if ($httpStatus === 429) {
                    $seconds = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT);
                    $retryAfter = now()->addSeconds($seconds === false ? 60 : max(1, min(86400, $seconds)));
                }
            }
        } catch (\Throwable) {
            /** 성공 후 응답 유실도 가능하므로 새로운 번호를 생성하지 않고 같은 요청을 보존합니다. */
        }
        DB::table('submission_outbox')->where('id', $id)->where('lease_id', $lease)->update(['status' => $status, 'http_status' => $httpStatus, 'error_code' => $code, 'receipt' => $receipt === null ? null : json_encode($receipt, JSON_THROW_ON_ERROR), 'lease_id' => null, 'lease_until' => null, 'retry_after' => $retryAfter, 'updated_at' => now()]);

        return $this->result(DB::table('submission_outbox')->where('id', $id)->first());
    }

    /** 재시도 중 환경·AI·알고리즘·번호가 바뀌면 다른 제출로 보내지 않고 중단합니다. */
    private function assertDestination(object $row, #[\SensitiveParameter] array $profile): void
    {
        if ($row->profile_key !== $profile['key'] || $row->profile_public_id !== $profile['public_id'] || $row->base_url !== $profile['base_url'] || $row->algorithm !== $profile['algorithm']) {
            throw new RuntimeException('기존 요청과 AI·알고리즘·서비스 주소가 다릅니다. 원래 설정을 사용하세요.');
        }
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        /** MySQL JSON 객체 키 순서가 바뀌어도 최초 명세 순서로 지문을 비교합니다. */
        $canonical = ['request_id' => $payload['request_id'] ?? null, 'draw_no' => $payload['draw_no'] ?? null, 'games' => $payload['games'] ?? null];
        if ($canonical['request_id'] !== $row->id || $canonical['draw_no'] !== (int) $row->draw_no || ! hash_equals($row->payload_hash, hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('저장된 요청 본문 지문이 다릅니다. 자동 전송을 중단합니다.');
        }
    }

    /** 비밀값·원문 네트워크 오류를 제외한 상태와 영수증만 반환합니다. */
    private function result(object $row): array
    {
        return ['request_id' => $row->id, 'run_id' => $row->run_id, 'profile' => $row->profile_key, 'draw_no' => (int) $row->draw_no, 'status' => $row->status, 'attempts' => (int) $row->attempts, 'http_status' => $row->http_status, 'error_code' => $row->error_code, 'retry_after' => $row->retry_after, 'payload' => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR), 'receipt' => $row->receipt ? json_decode($row->receipt, true, flags: JSON_THROW_ON_ERROR) : null];
    }
}
