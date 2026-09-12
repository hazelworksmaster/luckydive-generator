# LuckyDive 추천번호 생성기

PHP 8.4·Laravel 13 기반 독립 CLI 프로젝트입니다. 랜덤 생성, 공식 당첨번호 직접 수집, 초기 CSV 반입, 고정 필터와 최신 후보 준비, filtered-v1 생성 로직을 제공합니다.

## 현재 상태

- random-v1과 생성 원장 migration은 개발 DB에서 사용 가능합니다.
- filtered-v1 관련 테이블·metadata migration은 2026-09-08 사용자 승인 후 개발 DB에 적용했습니다.
- 1~1240회 당첨 이력과 고정 후보·filter5·filter6 적재를 완료했습니다. 현재 filtered-v1은 1241회 대상 생성이 가능합니다.
- weighted-v2 이전과 AI 프로필별 API 제출 기능을 구현했습니다. 실제 API 제출·자동 스케줄 등록·기존 draw 중단은 수행하지 않았습니다.

## 시작

Docker 실행 후 `./scripts/bootstrap.sh`로 의존성과 앱 키를 준비합니다. DB 변경은 실행하지 않습니다. DB 접속값은 `.env`에 직접 입력합니다. `DB_*`는 생성기 전용 계정을 사용하며 별도 draw DB 연결은 필요하지 않습니다.

```sh
./scripts/docker.sh local run --rm app php artisan lotto:generate --algorithm=random-v1 --count=5 --dry-run
./scripts/docker.sh local run --rm app composer test
```

알고리즘은 `config/recommendation.php`에 등록합니다. 생략 시 random-v1을 사용하고 알 수 없는 이름은 실패합니다. --count는 1~100이며 --save는 생성 결과를 실제 DB에 저장합니다. --dry-run과 --save는 함께 사용하지 않습니다.

## 직접 수집과 필터 준비

개발 DB의 초기 준비를 완료했습니다. 새 당첨번호를 반영하거나 준비 상태를 확인할 때 아래 명령을 사용합니다. 수집·후보 준비는 기본 미저장이며 --apply를 명시한 경우만 DB를 변경합니다.

```sh
./scripts/docker.sh local run --rm app php artisan lotto:import-draws storage/app/private/initial-draws/draw-history.csv --dry-run
./scripts/docker.sh local run --rm app php artisan lotto:sync-draws --dry-run
./scripts/docker.sh local run --rm app php artisan lotto:build-filtered-base --dry-run
./scripts/docker.sh local run --rm app php artisan lotto:prepare-filtered --dry-run
./scripts/docker.sh local run --rm app php artisan lotto:generate --algorithm=filtered-v1 --count=5 --dry-run
```

전체 고정 후보는 6,524,309개이며 filter5·filter6는 최신 기준 한 벌만 유지합니다. 후보 준비 완료 여부와 당첨 이력 지문을 확인한 후 번호를 선택합니다. filtered-v1 미리보기는 DB를 읽지만 저장하지 않습니다. random-v1 미리보기는 DB 연결 없이 동작합니다.

테이블·컬럼·comment·인덱스·CSV 형식·실행 순서·복구 제약은 [상세 설계](docs/filtered-pipeline-design.md)에 정리했습니다. 신규 테이블·컬럼은 MySQL comment를 포함하며 SQLite에서는 문서를 사전으로 사용합니다.

## 개발 및 운영

- 알고리즘: app/Algorithms, 고정·최신 후보: app/Services/Filtered, 직접 수집: app/Services/Lotto
- 생성 결과와 사용한 후보 기준은 recommendation_runs, 서비스 공개 원본은 LuckyDive가 소유합니다.
- 테스트는 SQLite :memory:로 강제 격리합니다. 실제 DB migration·데이터 변경은 대상과 영향 확인 후 실행합니다.
- `bash deploy.sh <이미지 태그>`로 GitHub에서 빌드한 운영 이미지를 내려받아 교체합니다. 운영 .env·storage 쓰기 권한·후보 적재 용량을 먼저 준비합니다.
- 비밀값은 Git·이미지·로그에 남기지 않습니다. 커밋은 요청할 때만 한글 메시지로 수행합니다.
- AGENTS.md는 별도 생성 확인을 받지 않아 만들지 않았습니다.


## 현재 필터 번호 생성

```sh
./scripts/docker.sh local run --rm app php artisan lotto:generate --algorithm=filtered-v1 --count=5 --dry-run
```

검증 건수는 당첨번호 1,240, 고정 후보 6,524,309, filter5 6,513,955, filter6 1,027,946입니다. 최신 이력과 준비 상태가 맞지 않으면 생성은 실패하며, 수집과 최신 필터 준비를 먼저 해야 합니다. 실제 추천번호 저장은 하지 않아 생성 원장은 0건입니다. 자세한 초기 적재 결과는 agents/initial-data-preflight.md를 참고하세요.

## 통계 기반 가중 추천

`weighted-v2`는 draw의 weighted_v2 통계 모델을 generator DB에서 독립 실행합니다. 전체 조합 → 회차별 가중 모델·후보 준비 → 공통 생성 순서이며, 최초 사용 전에 신규 migration과 데이터 준비가 필요합니다. `lotto:weighted-status`로 상태를 확인하세요. 명령과 저장·중복·복구 정책은 [가중 추천 설계](docs/weighted-pipeline-design.md)를 따릅니다.

## AI 프로필별 서비스 등록

`lotto:submit --profile=weighted --dry-run`으로 전송 계획을 확인하고, `--apply`로 5게임을 생성·저장·등록합니다. 오류 시 출력된 `--request-id`를 그대로 재사용합니다. 원장 migration이 먼저 필요하며 설정·로컬 HTTPS·준비만 실행·재시도 정책은 [등록 연동 문서](docs/submission-api-integration.md)를 따릅니다. 인증된 dry-run도 서비스의 토큰 마지막 사용 시각은 갱신합니다.

## GitHub 이미지 빌드

main push 시 테스트와 운영 이미지 실행 검증 후 GHCR에 이미지를 저장합니다. PR에서는 검증만 수행합니다. 서버 배포와 실제 DB 마이그레이션은 자동 실행하지 않습니다. 이미지 태그·권한·실행 방법은 [GitHub 빌드 문서](docs/github-image-build.md)를 참고하세요.

## 서버 배포

서버에 `deploy.sh`, `compose.prod.yml`, 운영 `.env`를 준비한 뒤 `bash deploy.sh sha-<전체커밋SHA>`를 실행합니다. 실행 절차와 복구 정책은 [서버 배포 문서](docs/server-deployment.md)를 따릅니다.

## 미출현·궁합 연결 추천

`overdue-chain-v1`은 직전 회차까지의 당첨 이력으로 결정적인 5조합을 만듭니다. 후보 테이블이나 추가 migration 없이 동작합니다.

```sh
./scripts/docker.sh local run --rm app php artisan lotto:generate --algorithm=overdue-chain-v1 --dry-run
# 과거 기준 재현: 1239회까지만 읽어 1240회 대상 조합을 생성합니다.
./scripts/docker.sh local run --rm app php artisan lotto:generate --algorithm=overdue-chain-v1 --draw-no=1240 --dry-run
```

미출현 기간이 긴 미사용 번호부터 궁합수를 연결하며, 5게임 전체의 30개 번호는 서로 중복되지 않습니다. 이 알고리즘은 `--count=5`만 허용합니다. 저장은 기존 `--save`로 명시하며 같은 이력으로 다시 실행하면 같은 조합이 나옵니다. 상세 규칙과 종료·효율 검토는 [알고리즘 문서](agents/overdue-chain-algorithm.md)를 참고하세요.
