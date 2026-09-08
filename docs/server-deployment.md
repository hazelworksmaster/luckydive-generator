# 서버 이미지 배포

## 최초 준비

Linux amd64 서버에 Docker Engine, Docker Compose v2의 `up --wait` 지원 버전과 `flock`(util-linux)이 필요합니다. 같은 디렉터리에 `deploy.sh`, `compose.prod.yml`, 운영 `.env`를 복사합니다. 소스·PHP·Composer는 서버에 설치하지 않아도 됩니다.

운영 `.env`에는 앱 키, DB 연결, 서비스 BASE URL과 프로필별 토큰을 지정합니다. 공인 HTTPS 인증서라면 LUCKYDIVE_CA_BUNDLE은 비워 둡니다. storage는 이 디렉터리 아래에 유지되며 이미지의 www-data 계정이 쓸 수 있도록 배포 시 필요한 하위 디렉터리의 소유권을 맞춥니다. 개인 파일이나 기존 로그 전체에 재귀 chown은 실행하지 않습니다.

비공개 GHCR 이미지라면 서버에서 `docker login ghcr.io`로 패키지 읽기 권한 계정의 인증을 먼저 준비합니다. 로그인 정보와 운영 .env를 Git에 저장하지 않습니다.

## 배포

```sh
bash deploy.sh sha-<전체커밋SHA>
# main에서 최근 빌드한 이미지도 선택할 수 있습니다.
bash deploy.sh main
```

이미지를 내려받아 네트워크가 차단된 컨테이너에서 저장 없는 랜덤 생성과 운영 설정의 CLI 부팅을 검증합니다. 성공하면 `luckydive-generator-prod` Compose 프로젝트의 app을 최대 60초 동안 정상 종료하도록 기다린 뒤 새 이미지로 교체합니다. 다른 앱과 local 스택은 중단하지 않습니다. 기존 운영 스택도 동일한 프로젝트명이어야 자동 교체됩니다.

새 컨테이너가 healthcheck를 통과하지 못하면 기존 컨테이너에서 확인한 이미지 ID로 복구합니다. 첫 배포에는 복구할 이전 이미지가 없습니다. 다운로드·사전 검증 실패 시 기존 컨테이너를 중단하지 않습니다. 이전 이미지는 삭제하지 않으며 필요하면 이전 SHA 태그로 다시 배포할 수 있습니다. 복구 실패는 오류로 출력하고 비정상 종료합니다.

## 운영 명령

이 앱은 CLI 프로그램이므로 컨테이너는 명령 실행 대기 상태로 유지됩니다. 크롤링·번호 생성·제출·마이그레이션은 자동 실행하지 않습니다. 주기 작업은 운영자가 cron 등에서 별도로 호출합니다. 실행 중인 장시간 배치가 끝난 뒤 배포하세요. 배포는 실행 중인 명령도 중단할 수 있습니다.

```sh
docker compose -p luckydive-generator-prod -f compose.prod.yml ps
docker compose -p luckydive-generator-prod -f compose.prod.yml exec -T app php artisan list
docker compose -p luckydive-generator-prod -f compose.prod.yml exec -T app php artisan lotto:generate --algorithm=random-v1 --count=5 --dry-run
# 접속 대상과 영향 확인 후 운영자가 수동 실행합니다.
docker compose -p luckydive-generator-prod -f compose.prod.yml exec -T app php artisan migrate --force
```

기존 scripts/docker.sh를 함께 복사했다면 `./scripts/docker.sh prod exec -T app php artisan list`도 사용할 수 있습니다. prod Compose에서 build는 제공하지 않습니다. 배포한 버전을 바꾸는 작업은 deploy.sh로 수행하세요.

## 검증 결과

- Bash 문법 검사와 prod Compose 구성 검사 통과.
- Docker 명령 대역을 사용해 정상 교체, 다운로드 실패, 사전 검증 실패, 기동 실패 후 복구, 복구 실패, 첫 배포 실패, 동시 실행 차단, 잘못된 태그의 8가지 흐름을 검증했습니다.
- 실제 서버 교체와 GHCR pull은 이번 작업에서 실행하지 않았습니다.
- 기동 대기는 [Docker Compose 공식 up 문서](https://docs.docker.com/reference/cli/docker/compose/up/)의 `--wait`와 healthcheck를 사용합니다. 이 검사는 DB 접속이나 실제 배치 성공을 보장하지 않습니다.
