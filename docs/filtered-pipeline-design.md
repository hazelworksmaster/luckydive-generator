# 생성기 당첨번호·필터 데이터 설계

최종 정리일: 2026-09-08

## 적용 범위와 현재 상태

generator가 공식 당첨번호 수집, 초기 CSV 반입, 고정 필터 1~4, 최신 filter5·filter6 준비, filtered-v1 번호 선택을 담당한다. draw DB 연결은 추가하지 않는다. 기존 draw 운영 배치는 전환 검증 후 별도 중단하며 이번 코드 변경에서 수정하지 않았다. 공개 제출·프로필·판정·성적 원본은 LuckyDive가 계속 소유한다. 외부 제출·자동 스케줄은 이번 구현에 포함하지 않는다.

새 migration은 2026-09-08 사용자 승인 후 개발 DB에 적용했다. 초기 이력 반입과 후보 적재는 사용자 실행 확인 후 완료했다. 상세 결과는 문서 하단을 따른다. 기존 recommendation_runs 데이터는 그대로 보존한다. 과거 작업 기록보다 이 문서의 설계와 상태가 우선한다.

## 테이블 사전

| 테이블 | 주요 컬럼 | 의미·수명 |
| --- | --- | --- |
| winning_numbers | round PK, number1~6, bonus, date, source, content_hash, updated_at | 전체 공식 당첨 이력. 정정은 같은 회차 갱신 |
| filtered_base_candidates | id PK, number1~6, number_range, number_sum | 전체 조합의 고정 필터 1~4 통과본. 최초 한 벌 |
| filter5_numbers | 고정 후보와 같은 컬럼 | 전체 역대 당첨번호 및 동일 이동 조합 제외본. 최신 한 벌 |
| filter6_numbers | 같은 컬럼, id는 1부터 연속 순번 | 차이·합계 빈도 조건 교집합. 최신 한 벌 |
| filtered_pipeline_state | id=1, base_version/count/prepared_at, ready, basis_round, draws_hash, algorithm, filter5_count, filter6_count, statistics, prepared_at | 공용 잠금 및 최신 후보의 완성·기준 정보 |
| recommendation_runs | 기존 컬럼 + metadata JSON nullable | 실제 생성 결과와 사용한 후보 기준 스냅샷 |

신규 테이블·모든 신규 컬럼에 MySQL comment를 추가했다. 기존 recommendation_runs에도 테이블 comment와 신규 metadata comment를 추가한다. 기존 컬럼 의미는 다음과 같다: id=실행 UUID, algorithm=알고리즘 버전 식별자, draw_no=추천 대상 회차(랜덤은 선택), game_count=게임 수, games=정규번호 배열, status=현재 generated, created_at/updated_at=생성/변경 시각. SQLite 테스트에서는 comment가 저장되지 않으므로 이 문서를 공통 사전으로 사용한다.

모든 신규 테이블은 MySQL InnoDB로 지정한다. 후보 테이블은 (number_range, number_sum) 인덱스를 둔다. 순차 준비·읽기는 id 기본키를 활용한다. SQL OR은 사용하지 않는다. 전체 all_numbers와 중간 filter1~4 테이블은 만들지 않는다.

## 정확한 필터 규칙

1. 1~45의 서로 다른 정수 6개 조합을 오름차순 사전식으로 순회한다.
2. filter1: 모두 홀수 또는 모두 짝수인 조합을 제외한다.
3. filter2: 3·4·5·6·7 중 하나의 수에 대해 6개 번호 모두가 배수이면 제외한다.
4. filter3: 연속 정수 3개 이상을 포함하면 제외한다.
5. filter4: 최대-최소가 25~43인 조합만 남긴다.
6. filter5: 최신까지 모든 당첨 정규번호와 동일 offset을 적용한 조합을 제외한다. offset 0 포함. 유효한 1~45 범위의 offset만 생성하며 이는 -45~45 검사와 결과가 같다. 보너스는 제외 비교에 사용하지 않는다.
7. filter6: 전체 이력에서 차이 빈도 상위 10개, 합계 빈도 상위 20개를 구해 두 조건을 동시에 만족하는 filter5 후보를 남긴다. 빈도 동점은 큰 수 우선으로 기존 draw와 동일하다.

미저장 전체 순회 검증 결과: 전체 8,145,060개, filter1 최초 탈락 175,560개, filter2 5,055개, filter3 458,420개, filter4 981,716개, 최종 고정 후보 6,524,309개. 이 숫자는 필터 단계별 순차 탈락 건수다.

## 최신 기준·실패 복구·정정

당첨번호는 공식 추첨일과 회차의 일치, 정규번호 오름차순·범위·중복, 보너스 중복을 검증한다. 최신 발표 가능 시각은 기존 draw의 한국 시간 토요일 20:45 기준을 재사용한다. 실제 발표 지연으로 공식 응답이 없으면 저장하지 않고 실패한다.

후보 준비는 1회부터 현재 최신 회차까지 누락이 없을 때만 가능하다. 최신+1회만 생성 대상으로 사용하며 --draw-no가 다르면 거부한다. 내용 hash를 포함한 전체 이력 지문과 준비 지문이 다르거나 ready=false면 생성하지 않는다. 과거 회차별 후보는 보관하지 않는다.

당첨번호 변경, 고정 후보 적재, 최신 후보 교체, 필터 추천 선택은 id=1 상태 행을 공통 잠금으로 사용한다. 후보의 DELETE/INSERT와 상태 갱신은 한 transaction이며 TRUNCATE는 사용하지 않는다. 중간 오류 시 이전 후보로 rollback한다. 수집에서 내용이 바뀌면 같은 transaction으로 ready=false를 기록한다. 읽기용 dry-run 준비는 일관된 transaction 스냅샷을 사용하되 쓰기 잠금은 잡지 않는다.

최종 선택은 최초 방식인 PHP random_int(1, 후보 수)로 서로 다른 후보 ID를 추출하고 WHERE IN으로 한 번에 조회하는 방식을 사용한다. 필터 결과 ID는 1부터 후보 수까지 연속이며, 같은 ID가 나오면 재추출한다. 결과는 DB 반환 순서와 무관하게 난수 추출 순서로 반환한다. SQL RAND()는 사용하지 않는다. 동일 조합의 중복 선택만 막고 게임 사이의 개별 번호 겹침은 허용한다. 요청 수량을 전부 확보하지 못하면 생성·저장하지 않는다. 선택 결과 원장에는 기준 회차·이력 지문·기본 필터 버전·통계 조건·준비 시각을 기록한다. 동일한 6개 번호 조합은 같은 실행 안에서 중복되지 않지만 실행 간 재추천을 제한하지 않는다.

## 초기 반입과 직접 수집

초기 데이터는 draw에서 아래 헤더의 UTF-8 CSV로 내보내 수동 반입한다. 파일에 DB 비밀번호나 개인 정보는 필요하지 않다. 5MB 이하, 같은 회차 중복 불허, 한 파일 전체 검증 후 원자적으로 적용한다. 파일 내 추가 열은 무시하며 필수 헤더는 모두 있어야 한다.

```csv
round,number1,number2,number3,number4,number5,number6,bonus,date
1,10,23,29,33,37,40,16,2002-12-07
```

수집 출처는 [동행복권 공식 결과](https://www.dhlottery.co.kr/lt645/result)의 공개 JSON을 기존 draw 클라이언트 기반으로 조회한다. 요청당 timeout 10초, retry 2회 설정, 연속 회차 사이 300ms 간격이다. 기본 동기화는 누락 회차와 최신 회차 재검증이며 과거 정정 확인은 --from/--to 범위로 수행한다. 수집 범위 중 하나라도 실패하면 이번 범위를 부분 저장하지 않는다. 운영 전에 초기 전체 이력을 파일로 반입해 대량 HTTP 요청을 줄인다.

## 실행 순서

아래 php 명령은 프로젝트에서 `./scripts/docker.sh local run --rm app` 뒤에 붙여 실행한다. --apply는 실제 DB 변경이며 사전 점검 결과와 실행 확인 이후에만 사용한다. 초기 migration도 같은 원칙을 따른다.

```sh
php artisan migrate --pretend
php artisan lotto:import-draws storage/app/draw-history.csv --dry-run
php artisan lotto:sync-draws --from=1240 --to=1240 --dry-run
php artisan lotto:build-filtered-base --dry-run
php artisan lotto:prepare-filtered --dry-run
php artisan lotto:generate --algorithm=filtered-v1 --count=5 --dry-run
```

적용 순서는 migration → CSV --apply → 누락 최신 결과 수집 --apply → 고정 후보 --apply → 최신 필터 --apply → 생성이다. 후보 준비와 데이터 반입은 --dry-run이 없더라도 기본 미저장이며 --apply가 필요하다. 생성은 기존처럼 기본 미저장이고 원장 저장에만 --save를 사용한다. 후보와 이력을 준비하기 전 filtered-v1은 사용할 수 없지만 random-v1은 계속 DB 없이 미리보기 가능하다.

## 용량·운영 제약

고정 후보는 약 652만 행이며 filter5도 비슷한 규모다. 회차별 누적은 없지만 인덱스와 InnoDB 오버헤드까지 포함하면 상당한 공간이 필요하다. 실용량은 실제 적재 후 확인해야 하며 적재 전에 여유 디스크와 undo/redo 공간을 별도로 점검한다. 최초 적재 및 재준비는 하나의 transaction이라 오래 걸릴 수 있고 동시 생성은 대기 또는 잠금 timeout으로 실패할 수 있다. 명령은 재시도 가능하고 기존 완료본을 부분 노출하지 않는다. 현재 중간 진행 재개는 제공하지 않는다.

필터5 전체 보관은 사용자에게 익숙한 기존 단계 구조를 유지하기 위한 선택이다. 향후 실측에서 저장·재계산 비용이 크면 filter5를 계산 중간 결과로만 취급하는 최적화를 별도 검토한다. 수백만 건 MySQL 실제 적재 성능과 다중 프로세스 잠금은 이번 SQLite 테스트만으로 검증됐다고 주장하지 않는다.

## Migration 사전 점검

접속 대상 local / 100.71.40.22:3306 / luckydive_generator_dev. 기존 migrations 1행, recommendation_runs 0건을 정확한 COUNT로 확인했다. MySQL 8.4.10이며 기존 테이블은 InnoDB다. 새 migration은 테이블 5개 생성, 상태 초기 행 1개 추가, recommendation_runs에 nullable metadata와 테이블 comment 추가다. 후보·당첨번호·추천번호는 자동 적재하지 않는다.

되돌리면 신규 이력·후보 테이블과 metadata가 삭제된다. 적용 직후 비어 있으면 기존 생성 결과는 유지되지만 실제 데이터를 적재한 후에는 백업을 선행해야 한다. MySQL DDL은 전체가 원자적이지 않아 일부 실패 시 migrate:rollback을 무조건 실행하지 않고 생성된 테이블·migration 원장을 점검해 전진 복구한다.


## Migration 적용 결과 (2026-09-08)

사용자 실행 확인 후 개발 DB luckydive_generator_dev에 새 migration을 적용했다. 상태는 배치 2 / Ran이다. 신규 테이블 5개의 InnoDB 엔진, 테이블 comment 및 모든 컬럼 comment를 실제 information_schema 조회로 확인했다. recommendation_runs의 테이블 comment와 nullable JSON metadata comment도 확인했다. 기존 컬럼 설명은 위 사전에 유지한다.

winning_numbers, filtered_base_candidates, filter5_numbers, filter6_numbers, recommendation_runs는 각각 0건이다. filtered_pipeline_state만 초기 행 1건이며 ready=false다. 당첨번호 반입, 후보 적재 및 추천 생성·제출은 실행하지 않았다.


## 초기 데이터 준비 완료 (2026-09-08)

사용자가 디스크 여유와 실제 적재 실행을 확인한 뒤 순서대로 적용했다. 대상은 개발 DB luckydive_generator_dev이며, 기존 draw 원본 DB는 변경하지 않았다.

| 데이터 | 정확한 검증 건수 |
| --- | ---: |
| winning_numbers | 1,240 |
| filtered_base_candidates | 6,524,309 |
| filter5_numbers | 6,513,955 |
| filter6_numbers | 1,027,946 |
| recommendation_runs | 0 |

상태 ready=1, 기준 회차 1240, 추천 대상 1241을 확인했다. 전체 당첨 이력 지문과 준비 지문이 일치하며, 모든 건수는 저장 없는 사전 계산과 같다. filtered-v1 5게임 CLI dry-run도 성공했다. 추천 결과 저장·외부 API 제출·자동 스케줄 등록은 실행하지 않았다.

MySQL information_schema의 데이터와 인덱스 크기 합계는 약 725.1MiB다. 이는 조회 시점의 테이블 크기이며 undo/redo·binlog·파일시스템 여유까지 포함하는 수치는 아니다. 서버 디스크 여유는 사용자 확인을 근거로 진행했다.

대량 적재는 MySQL 5,000행 단위(45,000 바인딩)로 처리하고 SQLite는 500행으로 유지했다. 처리 건수는 commit 전임을 표시한다. 묶음 조정 후 격리 테스트 16개/3,386단언 통과, 실제 MySQL 적재 및 최종 건수 검증 통과. 고정 후보 준비 완료 시각은 10:23:22, 최신 필터 준비 완료 시각은 10:39:16(한국 시간)이다. 여러 프로세스를 동시에 실행하는 부하 시험은 수행하지 않았다.

CSV와 사전/사후 JSON은 storage/app/private/initial-draws에 보존했다. 고정 후보는 이후 재사용하고, 새 당첨번호 수집 후 최신 필터만 다시 준비한다. 앞선 미적재·대기 상태 기록은 이 결과로 대체한다.
