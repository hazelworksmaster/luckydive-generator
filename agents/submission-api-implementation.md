# AI 등록 기능 구현 기록

2026-09-08

AI별 토큰은 변경 없이 유지하고 단일 API 토큰 설정을 프로필별 설정으로 교체했다. BASE URL은 로컬 HTTP 301을 확인한 뒤 HTTPS로 교정했다. 서비스 DB의 토큰 해시 SELECT로 세 활성 프로필을 확인하고 public_id를 .env에 고정했다. 로컬 공개 PEM만 복사했고 개인키는 복사하지 않았다.

신규 submission_outbox migration, HTTPS API 클라이언트, 프로필 검증, 공통 생성 후 등록, 준비만 실행, 임대 기반 전송 중복 방지와 같은 UUID·본문 재시도 명령을 구현했다. MySQL JSON 키 순서 재정렬을 반영해 본문 지문을 정규화했다. 원장에는 토큰과 HTTP 예외·오류 응답 원문을 저장하지 않는다.

실제 등록·인증 GET·migration은 아직 하지 않았다. 토큰 없는 컨테이너 HTTPS 요청 401과 DB SELECT로 확인했다. 최초 36개 테스트 통과 후 원장 저장 실패 rollback과 만료 임대 복구 테스트를 추가했다. 최종 결과는 후속 기록을 따른다. 구체적인 사전 점검 건수와 승인 후 절차는 docs/submission-api-integration.md에 기록했다.

## 등록 원장 마이그레이션 및 실제 인증 검증 완료

2026-09-08 사용자 승인 후 local MySQL 100.71.40.22의 luckydive_generator_dev에 2026_09_08_000004_create_submission_outbox_table을 적용했다. 원장 신규 테이블 생성만 수행했고 기존 데이터 변경·삭제는 없다.

실제 로컬 HTTPS API로 random/filtered/weighted의 lotto:submit --dry-run을 실행했다. 필터드와 가중은 1241회, 5게임, remaining_submissions=1로 성공했고 랜덤은 기존 활성 제출 2건으로 한도 소진 오류가 정상 반환됐다. 세 토큰의 인증 마지막 사용 시각은 갱신됐으며 번호 POST는 실행하지 않았다.

검증 후 recommendation_runs=0, weighted_selections=0을 확인했다. 서비스 활성 제출은 랜덤 2건·필터드 1건·가중 1건으로 기존 상태를 유지했다. 신규 outbox 존재와 migration 기록·빈 원장·모든 컬럼 comment·인덱스·외래키를 확인했다. 실제 등록 검증은 아직 수행하지 않았으며 신규 API 등록은 별도 실행 범위다. 이전 코드 검증 결과는 38개 테스트·3533개 assertion 통과다.
