# 가중 추천 이전 작업 기록

작성일: 2026-09-08

## 구현

- draw의 특성 계산·분포 분석·weighted_v2 모델·가중 무작위 순위 계산 코어를 generator로 이전했다.
- 전체 조합 독립 적재·재개, 모델 및 후보 준비, weighted-v2 공통 생성, 원장과 선택 이력의 원자적 저장을 추가했다.
- 신규 5개 테이블에 한글 comment·FK·인덱스·회차 전체 동일 조합 고유 제약을 추가했다.
- 당첨 정정·설정 변경·회차 변경 시 오래된 후보 사용을 막고, 재준비 시 과거 모델·선택 이력을 보존한다.
- 최초 필터드 random_int 일괄 조회 방식은 유지했다.

## 검증과 남은 절차

draw 원본 클래스를 별도 컨테이너에서 직접 로딩하고 네트워크 없는 SQLite 메모리 DB에서 고정 8개 조합·3개 이력·seed 20260827의 기준 결과를 추출했다. 모델 지문은 09104560bcfb197a54ed243b802b5e1576ab87b5d53641fea2cad7368cc0263f이며 후보 순서는 8,7,6,5다. generator 테스트에서 지문·순서·가중치를 비교한다.

실제 DB migration·8145060개 조합 적재·최신 가중 후보 준비는 사전 점검 결과 공유 후 실행 승인을 받아야 한다. 완료 여부는 후속 기록으로 명시한다. draw 운영 cron과 서비스 추천 동기화는 유지한다.

## 실제 DB 사전 점검

- 환경 local, MySQL 100.71.40.22:3306, 실제 DB luckydive_generator_dev.
- winning_numbers 1240건(최신 1240회), filtered_base_candidates 6524309건, filter5_numbers 6513955건, filter6_numbers 1027946건, recommendation_runs 0건.
- 신규 lotto_combinations, weighted_models, weighted_pools, weighted_pool_entries, weighted_selections는 모두 아직 없다.
- migrate --pretend로 신규 5개 테이블의 MySQL DDL·comment·인덱스·FK 생성 SQL을 확인했다. 실제 DDL은 실행하지 않았다.
- 승인 후 계획은 신규 migration 1건, 전체 조합 8145060건, 1241회용 모델 1건·풀 1건·후보 10000건이다. 준비는 1240회까지를 사용하며 추천·선택 이력은 생성하지 않는다. 기존 데이터 삭제나 초기화는 없다.
- 적재 중단은 배치 재개, 모델·후보 저장 실패는 transaction rollback으로 복구한다. MySQL DDL 부분 실패는 자동 rollback되지 않으므로 생성 상태를 먼저 재점검한다.

## 실제 DB 적용 완료

2026-09-08 사용자 승인 후 local MySQL 100.71.40.22:3306의 luckydive_generator_dev에 신규 migration 2026_09_08_000003을 적용했다.

전체 조합 적재는 600000건에서 잠시 중단됐다. 제안했던 속도 개선 코드는 적용되지 않았으며 기존 2000건 단위 바인딩 INSERT를 유지했다. 동일 명령으로 재개하여 나머지 7545060건을 적재했고 최종 8145060건과 complete=true를 확인했다. 중단 상태를 확인한 뒤 실제 진행 건수 증가를 추적해 완료까지 검증했다.

`php -d memory_limit=512M artisan lotto:prepare-weighted --target-round=1241 --seed=20260908 --apply`로 1240회까지 분석해 모델 1건, 풀 1건, 가중 후보 10000건을 저장했다. 실제 평가 후보는 100000건이다.

- model_id=1, pool_id=1
- draws_hash=5a4aaea35ea938f54d6393342ea66b21ebdc0aab8540072dc39568e21d500f6a
- config_hash=acc50922ee5068c2474f1f578fc93a3f02291ced6c1fbfe3723bc820a68100ac
- model_hash=de87436668cd549b08d7c3db8073175a70b242f583b4c513ed4610fb8274de05

`lotto:generate --algorithm=weighted-v2 --count=5 --dry-run`으로 실제 MySQL에서 서로 다른 5게임 반환 및 saved=false를 확인했다. 이후 recommendation_runs=0, weighted_selections=0으로 미리보기가 발급 이력을 소비하지 않았음을 확인했다.

최종 건수는 winning_numbers=1240, filtered_base_candidates=6524309, filter5_numbers=6513955, filter6_numbers=1027946이며 기존 건수를 유지한다. 이번 run --rm 임시 컨테이너는 모두 종료·제거됐고, 기존 종료 상태의 local-app-1만 남아 있음을 확인했다. 코드 검증은 이전 단계의 28개 테스트 및 3485개 assertion 통과 결과를 유지하며 이 적용 단계에서는 프로그램 코드를 바꾸지 않았다.

draw의 서비스 추천 동기화·운영 배치는 변경하지 않았다. 향후 generator의 외부 AI 제출 연동과 실제 운영 cron 의존성 전환을 확인한 후 draw 종료 여부를 결정한다.
