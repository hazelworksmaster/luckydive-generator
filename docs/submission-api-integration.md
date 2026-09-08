# LuckyDive 외부 AI 등록 연동

작성일: 2026-09-08

## 설정과 책임

BASE URL은 HTTPS 기본 주소만 입력한다. API 경로 `/api/v1/lotto/ai/context`, `/api/v1/lotto/ai/submissions`는 코드가 붙인다. HTTP, 경로·사용자 정보·쿼리·fragment가 있는 주소는 거부하고 리디렉션을 따라가지 않는다. TLS 검증은 항상 유지한다.

```dotenv
LUCKYDIVE_API_BASE_URL=https://local-www.luckydive.co.kr
LUCKYDIVE_CA_BUNDLE=/app/storage/app/private/certs/luckydive-local.pem
LUCKYDIVE_AI_RANDOM_TOKEN=
LUCKYDIVE_AI_RANDOM_PROFILE_ID=
LUCKYDIVE_AI_FILTERED_TOKEN=
LUCKYDIVE_AI_FILTERED_PROFILE_ID=
LUCKYDIVE_AI_WEIGHTED_TOKEN=
LUCKYDIVE_AI_WEIGHTED_PROFILE_ID=
```

PROFILE_ID는 관리자의 내부 숫자 ID가 아니라 서비스 프로필 public_id UUID다. 토큰의 AI와 비교해 잘못된 프로필 등록을 막는다. 별칭 random/filtered/weighted와 알고리즘 random-v1/filtered-v1/weighted-v2의 연결은 config/recommendation.php가 소유한다. 서비스의 algorithm_version은 서비스 프로필 설정이며 generator 알고리즘 식별자와 같을 필요는 없다. 수신 영수증에 실제 서비스 버전을 보관한다.

로컬 compose는 local-www.luckydive.co.kr을 Docker 호스트로 연결한다. 로컬 공개 인증서만 generator의 private/certs에 복사하고 CA_BUNDLE로 지정한다. 개인키는 복사하지 않는다. 호스트에서 직접 PHP를 실행할 경우 인증서 경로를 해당 환경 절대경로로 바꾼다. 운영의 공인 인증서는 CA_BUNDLE을 비워 시스템 신뢰 저장소를 사용한다. 로컬 인증서는 갱신 후 공개 PEM을 다시 복사한다. .env 및 private 파일은 Git과 Docker 이미지에서 제외된다.

토큰은 .env 또는 운영 비밀 저장소만 사용한다. DB·HTTP 예외 원문·로그·문서·명령 인자에 기록하지 않는다. 프로필별 토큰 재발급은 .env 토큰만 교체한다. 동일 프로필 UUID와 원래 BASE URL이면 기존 요청 UUID로 재시도할 수 있다. 캐시된 설정을 쓰는 환경은 설정 캐시를 다시 생성해야 한다.

## 명령

generator 디렉터리에서 아래처럼 실행한다. 모든 신규 제출은 정확히 5게임이며 알고리즘은 AI 별칭 설정에서 선택한다.

```bash
# 현재 회차·프로필·한도·대상만 조회한다. 번호 생성·generator DB 저장·POST는 하지 않는다.
./scripts/docker.sh local run --rm app php artisan lotto:submit --profile=weighted --dry-run

# 번호 생성과 원장을 먼저 저장한 뒤 실제 등록한다.
./scripts/docker.sh local run --rm app php artisan lotto:submit --profile=weighted --apply

# 생성 번호를 먼저 확인하려면 등록 없이 저장까지만 수행한다.
./scripts/docker.sh local run --rm app php artisan lotto:submit --profile=weighted --apply --prepare-only

# 준비된 요청 또는 응답을 잃은 요청은 출력된 UUID를 그대로 사용한다.
./scripts/docker.sh local run --rm app php artisan lotto:submit --profile=weighted --request-id=원래_UUID --apply
```

기본 실행은 dry-run과 같다. dry-run은 새 번호를 만들지 않아 검토용 숫자와 실제 등록 숫자가 달라지는 문제를 피한다. 번호 검토에는 prepare-only를 사용한다. 이 옵션도 실행 원장과 가중 선택 이력을 실제로 저장하므로 DB 변경 승인 대상이다. --request-id 없는 apply는 매번 새 등록 요청이다. 오류가 난 요청은 반드시 출력된 UUID를 사용한다.

인증된 GET context도 서비스 lotto_ai_tokens.last_used_at을 갱신한다. 따라서 dry-run은 번호나 등록 원장을 만들지는 않지만 서비스 DB 전체에 완전한 읽기 전용 작업은 아니다. 실제 인증 검증에는 이 영향을 포함해 승인받는다. 구현 사전 확인은 토큰 해시를 서비스 DB에서 SELECT하고 토큰 없는 HTTPS 요청으로 수행했다.

## 요청 상태와 재시도

생성·공통 실행 원장·가중 선택·submission_outbox 준비는 같은 로컬 transaction으로 확정한다. 최초 요청 본문은 UUID·회차·게임 순서가 불변이다. MySQL JSON 객체 키 순서가 바뀌어도 명세 순서로 정규화해 지문을 검증한다. HTTP 호출은 DB transaction 밖에서 한다.

| 상태 | 의미와 다음 행동 |
|---|---|
| pending | 준비 완료, 같은 UUID의 apply로 전송 |
| sending | 임대 소유 프로세스 전송 중. 2분 임대 만료 전 중복 전송 차단 |
| unknown | 연결 실패·응답 유실·5xx·비정상 성공 응답. 실제 접수 가능성이 있으므로 같은 UUID·본문으로 재전송 |
| rejected | 4xx 거부. 오류 코드 확인 후 같은 요청으로 재시도; 429는 retry_after 이후 |
| submitted | 검증된 201 영수증 보관. 같은 UUID 실행은 로컬 영수증 반환, 재등록하지 않음 |

신규 요청은 context 회차·한도를 따르고 마감 후 자동 회차 변경은 하지 않는다. 필터드·가중 후보의 준비 회차가 서비스 현재 회차와 다르면 실패한다. 토요일 20:00 마감과 20:45 발표 사이에는 다음 회차 후보가 아직 준비되지 않았을 수 있으므로 새 이력 수집·후보 준비 후 실행한다.

기존 요청의 재전송은 현재 한도가 없거나 회차가 바뀌어도 원래 내용으로 최초 영수증 복구를 시도한다. 서비스는 성공한 동일 UUID·본문 요청의 영수증을 재전달한다. 영수증은 당시 접수 증명이며 이후 삭제 여부 등 현재 상태를 보장하지 않는다. HTTP POST 자동 재시도·새 UUID 자동 발급·실패 시 번호 재생성은 하지 않는다. 프로필·알고리즘·BASE URL이 바뀐 기존 요청은 전송하지 않는다.

가중 선택 이력은 생성 확정 시 소비되므로 이후 서비스가 거부해도 재발급 후보로 자동 복구하지 않는다. 추천 원장과 요청 이력을 보존하고 실패 사유를 해결한다. 외부 서비스 등록은 로컬 transaction과 분산 transaction으로 묶을 수 없으므로 UUID 멱등성과 영수증 복구를 사용한다.

## 신규 테이블과 인덱스

submission_outbox의 모든 테이블·컬럼에 한글 comment가 있다. id는 서비스 요청 UUID PK, run_id는 recommendation_runs FK 및 UNIQUE다. profile_key/public_id와 algorithm/base_url은 최초 대상 고정용이다. draw_no/payload/payload_hash는 불변 요청, status/attempts는 처리 상태, lease_id/lease_until은 중복 전송 방지 임대, retry_after는 429 대기, http_status/error_code는 비밀값 없는 결과, receipt는 검증한 성공 영수증, created_at/updated_at은 처리 시각이다. 프로필+회차+상태와 상태+재시도 시각 인덱스를 둔다. 토큰 컬럼은 없다.

## 실제 적용 사전 점검

- generator local MySQL 100.71.40.22의 luckydive_generator_dev: 원장 테이블 미생성, recommendation_runs=0, weighted_selections=0.
- 서비스 local MySQL host.docker.internal의 luckydive: 세 프로필 토큰이 유효하고 활성 상태다.
- 1241회 활성 제출은 랜덤 제너레이터 2건, 필터드 픽 1건, 밸런스 랩 1건이다. 랜덤은 현재 추가 등록 불가, 나머지는 각 1건 여유다.
- HTTPS 기본 주소와 자체 서명 공개 인증서를 맞춘 뒤 generator 컨테이너에서 인증서 검증을 유지한 무토큰 GET의 401을 확인했다. 인증 실패는 예상된 응답이며 실제 토큰 GET과 POST는 아직 하지 않았다.
- 신규 migration 2026_09_08_000004의 MySQL DDL을 pretend로 확인했다. 실제 DDL과 등록은 승인 대기다.

제안 검증 범위는 migration으로 빈 원장 1개 생성 후 필터드·가중 프로필에 각 1건(5게임)을 등록하는 것이다. 예상 추가는 generator 실행 2건·요청 2건·가중 선택 5건, 서비스 제출 2건·성공 요청 원장 2건이다. 인증 과정에서 해당 토큰 마지막 사용 시각이 갱신된다. 기존 데이터는 삭제하지 않는다. 실패 시 원래 UUID로 복구하고 자동 재생성하지 않는다. 외부 접수는 DB rollback으로 취소되지 않으며 필요 시 마감 전 별도 승인으로 소프트 삭제하고 요청 영수증은 보존한다. 요청 원장이 생긴 뒤 rollback하거나 삭제하면 복구 정보가 사라지므로 백업과 별도 승인이 필요하다.

정기 실행·기존 draw 동기화 중단·삭제 API 연동은 이번 작업에서 수행하지 않는다.

## 등록 원장 마이그레이션 및 실제 인증 검증 완료

2026-09-08 사용자 승인 후 local MySQL 100.71.40.22의 luckydive_generator_dev에 2026_09_08_000004_create_submission_outbox_table을 적용했다. 원장 신규 테이블 생성만 수행했고 기존 데이터 변경·삭제는 없다.

실제 로컬 HTTPS API로 random/filtered/weighted의 lotto:submit --dry-run을 실행했다. 필터드와 가중은 1241회, 5게임, remaining_submissions=1로 성공했고 랜덤은 기존 활성 제출 2건으로 한도 소진 오류가 정상 반환됐다. 세 토큰의 인증 마지막 사용 시각은 갱신됐으며 번호 POST는 실행하지 않았다.

검증 후 recommendation_runs=0, weighted_selections=0을 확인했다. 서비스 활성 제출은 랜덤 2건·필터드 1건·가중 1건으로 기존 상태를 유지했다. 신규 outbox 존재와 migration 기록·빈 원장·모든 컬럼 comment·인덱스·외래키를 확인했다. 실제 등록 검증은 아직 수행하지 않았으며 신규 API 등록은 별도 실행 범위다. 이전 코드 검증 결과는 38개 테스트·3533개 assertion 통과다.
