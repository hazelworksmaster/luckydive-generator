# 통계 기반 가중 추천 설계

최종 정리일: 2026-09-08

## 역할과 계산 규칙

generator는 draw의 weighted_v2 계산 코어를 독립 보유하고 공통 알고리즘 식별자 weighted-v2로 제공한다. draw DB에 연결하지 않는다. 당첨번호는 generator의 winning_numbers에서 읽으며 최신 발표 회차까지 누락 없는 이력만 허용한다. 추천 대상은 최신 발표 회차 다음 회차다.

전체 조합 분포 50%, 전체 당첨 이력 35%, 최근 200회 15%를 혼합하고 목표 분포를 전체 조합 분포로 나눈 배율을 0.75~1.25로 제한한다. 특성 비중은 합계 20%, 홀짝 15%, 저고·번호 범위·최장 연속수·AC값·직전 회차 중복 각 10%, 끝자리 중복·번호 쌍 동반 출현 각 7.5%다. 특성 배율의 가중 기하 평균과 draw의 구간·반올림 규칙을 유지한다.

전체 조합에서 기본 100000개 적격 후보를 균등 표본 추출하고, log(U)/가중치로 무작위 순위를 매겨 상위 10000개를 보관한다. 과거 1등 정규번호 6개와 완전히 같은 후보는 제외하며 보너스는 비교하지 않는다. 준비 풀은 서로 다른 조합 사이 최대 5개 번호 겹침을 허용한다. draw의 일회성 생성 명령에 있던 최대 3개 겹침 제한은 저장형 풀 정책이 아니므로 적용하지 않는다.

실제 선택은 draw 저장형 선택의 난수 시작 순번 이후 첫 미발급 후보를 찾고 끝이면 처음으로 돌아가는 방식을 유지한다. 이는 남은 후보의 균등 추출과 같지 않으며, 이미 발급된 위치가 늘면 선택 분포가 달라질 수 있다. 이번 이전에서는 기존 동작을 보존한다. 필터드의 random_int·일괄 조회와는 별도 알고리즘이다.

## 데이터 사전

모든 신규 테이블·컬럼에 한글 MySQL comment가 있다. SQLite에서는 아래 설명을 기준으로 한다.

| 테이블 | 주요 컬럼과 의미 | 인덱스·수명 |
|---|---|---|
| lotto_combinations | id=사전식 순번, number1~6=조합, odd_count=홀수 수, low_count=22 이하 수, number_sum=합계, number_range=범위, max_consecutive_run=최장 연속수, ac_value=AC, section1~5_count=구간별 수 | id PK, 통계 집계 컬럼별 인덱스. 총 8145060건, 고정 보관 |
| weighted_models | target_round=대상, basis_round=기준, algorithm=weighted-v2, draws_hash=당첨 이력, config_hash=설정, model_hash=계산 결과 지문, profile=전체 모델 JSON, created_at=생성시각 | 대상 회차 인덱스. 정정마다 새 개정 추가 |
| weighted_pools | model_id=원본 모델, target_round=대상, ready=사용 가능, seed=표본 추출 seed, candidate_count=평가 수, entry_count=보관 수, historical_winner_count=제외할 고유 당첨 조합 수, created_at=준비시각 | 모델 FK, 대상·ready·id 인덱스. 과거 풀 보존 |
| weighted_pool_entries | pool_id=소속, sequence=연속 순번, combination_id=전체 조합 ID, number1~6=후보, sampling_weight=추출 배율 | 풀 FK, 풀+순번 및 풀+조합 UNIQUE |
| weighted_selections | run_id=공통 실행, entry_id=원본 후보, target_round=대상, combination_id=조합, sequence=실행 안 순서, selection_seed=선택 seed, created_at=저장시각 | 원장·후보 FK, 회차+조합 UNIQUE, 실행+순서 UNIQUE |

recommendation_runs에는 기존 구조대로 게임과 알고리즘·기준 회차·모델/풀 ID·지문·선택 seed·원본 후보 참조를 남긴다. weighted_selections는 외부 공개 여부가 아니라 generator에서 확정 저장한 선택 이력이다. 외부 제출 데이터의 소유자는 계속 LuckyDive이며 제출 API는 이번 범위에 포함하지 않는다.

## 명령

아래 명령은 generator 디렉터리에서 실행한다. Docker에서는 앞에 `./scripts/docker.sh local run --rm app`을 붙인다.

```bash
php artisan lotto:weighted-status
php artisan lotto:build-combinations --dry-run
php artisan lotto:build-combinations --apply
php artisan lotto:prepare-weighted --seed=20260908 --dry-run
php artisan lotto:prepare-weighted --seed=20260908 --apply
php artisan lotto:generate --algorithm=weighted-v2 --count=5 --dry-run
php artisan lotto:generate --algorithm=weighted-v2 --count=5 --save
```

build-combinations는 DB에 all_numbers를 만들지 않고 조합을 순회하여 독립 적재한다. 배치마다 transaction을 확정하므로 중단 시 마지막 정상 순번부터 같은 명령으로 재개한다. `--max-rows`는 이번 실행 적재량을 제한한다. 부분 적재 상태는 가중 모델 준비에 사용할 수 없다. 고정 조합을 준비하는 동안 필터드 후보는 변경하지 않는다.

prepare-weighted는 `--target-round`, `--candidate-count`(기본 100000), `--entry-count`(기본 10000), `--seed`를 지원한다. 기본은 미저장이며 `--apply`만 저장한다. 동일 모델이 있으면 덮어쓰지 않고 실패한다. 당첨 이력이나 설정이 달라지면 새 모델·풀 개정으로 준비할 수 있다. 과거 개정은 삭제하지 않고 ready만 해제한다. 새 풀에서도 회차 전체의 기존 발급 조합을 제외한다.

generate의 기본/--dry-run은 후보를 소비하지 않아 이후 다시 나올 수 있다. --save는 공통 실행 원장과 선택 이력을 하나의 transaction에서 확정한다. 요청 전체 수량이 부족하거나 이력 저장에 실패하면 일부 결과도 저장하지 않는다. 수집·후보 확정·선택은 기존 filtered_pipeline_state 공용 행 잠금을 같은 순서로 사용한다. weighted_pools 잠금과 회차+조합 고유 제약으로 같은 회차 중복 발급을 막는다.

당첨 이력 변경 시 필터드와 가중 후보를 함께 무효화한다. 가중 준비는 무거운 계산 전후 이력 지문을 비교해 계산 도중 정정된 결과를 저장하지 않는다. 생성 때도 최신 회차·이력·설정 지문을 확인한다. seed 재현은 같은 조합 데이터·설정·이력에서 가능하며, 실제 선택은 미발급 상태도 같아야 한다.

## 실제 DB 적용과 복구

코드와 테스트를 먼저 검증한 뒤 weighted-status 및 migrate --pretend로 대상·테이블·건수를 확인하고 사용자의 명시적 승인을 받아 실행한다. 마이그레이션은 신규 5개 테이블만 추가한다. 초기 적재는 전체 조합 8145060건, 모델 1건, 풀 1건, 후보 10000건이며 저장 없는 생성 확인은 실행·선택 이력을 만들지 않는다.

전체 조합 적재는 배치 단위 재개가 가능하다. 모델·풀 적재는 단일 transaction이라 실패 시 해당 준비를 rollback한다. MySQL DDL은 전체 migration transaction으로 되돌릴 수 없으므로 실패 시 이미 생성된 테이블을 먼저 점검한다. 선택 이력이 생긴 뒤 migration rollback은 원장 참조와 과거 풀을 잃으므로 백업 및 별도 승인이 필요하다. 자동 삭제·재초기화는 하지 않는다.

## draw 종료 조건

이번 이전은 추천 계산 역할의 대체다. v2/apps/www의 LottoRecommendationSyncService는 여전히 draw 연결의 recommend_numbers를 읽고 routes/console.php에서 lotto:sync-recommendations를 예약한다. 해당 경로를 generator의 LuckyDive 제출 API로 전환하고 회차별 등록·결과 판정이 정상임을 확인하기 전에는 draw 추천 배치를 중단하지 않는다.

사용자 웹의 당첨결과·상금·판매점 수집은 LottoDrawSyncService가 동행복권을 직접 호출한다. 코드상 추천 동기화 외 추가 draw DB 참조는 확인되지 않았지만, 실제 운영 cron·서버·외부 소비자의 잔여 사용 확인은 별도로 필요하다. draw 소스·DB 삭제와 배치 중지는 이번 작업에서 실행하지 않는다.
