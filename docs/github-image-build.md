# GitHub 운영 이미지 빌드

## 실행 범위

`.github/workflows/docker-build.yml`은 독립 generator 저장소에서 실행합니다.

- main 대상 PR: 테스트와 운영 이미지 빌드·실행 검증.
- main push: 위 검증 후 GHCR에 이미지 저장.
- Actions의 수동 실행: 선택한 브랜치를 검증하며 main을 선택한 경우만 GHCR에 저장.
- 서버 배포, 당첨번호 수집, 후보 준비, 추천번호 제출과 실제 DB 마이그레이션은 실행하지 않습니다. 마이그레이션은 운영자가 별도로 수동 실행합니다.

테스트는 SQLite 메모리 DB만 사용하고 컨테이너 네트워크를 차단합니다. 운영 이미지에서도 DB를 사용하지 않는 random-v1 미리보기로 CLI 실행을 확인합니다.

## 이미지와 인증

이미지 경로는 소문자로 변환한 `ghcr.io/<저장소 소유자>/<저장소 이름>`입니다. 현재 저장소는 `ghcr.io/hazelworksmaster/luckydive-generator`입니다.

- `sha-<전체 커밋 SHA>`: 해당 소스 버전을 식별하는 태그. 운영 실행 시 이 태그 또는 이미지 digest를 고정합니다.
- `main`: main에서 마지막으로 성공한 빌드 이미지.
- 기본 대상 아키텍처: `linux/amd64`. ARM 서버는 별도 플랫폼 빌드 구성이 필요합니다.

업로드는 GitHub가 실행마다 제공하는 `GITHUB_TOKEN`의 `packages: write` 권한을 사용합니다. DB 비밀번호, LuckyDive API 토큰, 운영 `.env`와 별도 PAT를 빌드 Secrets에 등록할 필요가 없습니다. 조직 정책에서 Actions의 패키지 게시를 허용해야 하며, 같은 이름의 패키지가 이미 있다면 이 저장소의 Actions 접근 권한을 확인합니다.

`.env`, 의존성의 로컬 사본, 로그, 캐시, 개인 반입 파일과 인증서는 Docker 빌드에서 제외합니다. 의존성은 composer.lock 기준으로 이미지 안에 설치합니다.

## 실행 확인

워크플로를 커밋해 main에 push하면 GitHub의 Actions에서 `Docker 이미지 빌드` 실행 결과를 확인할 수 있습니다. 성공하면 저장소 소유자의 Packages와 실행 요약에서 이미지 태그를 확인합니다. 비공개 패키지를 서버에서 pull하려면 서버에 패키지 읽기 인증이 별도로 필요합니다.

```sh
docker pull ghcr.io/hazelworksmaster/luckydive-generator:sha-<전체-커밋-SHA>
```

서버는 이미지 전용 prod Compose와 `deploy.sh`로 배포합니다. [서버 배포 문서](server-deployment.md)를 따릅니다. GitHub Actions는 서버 배포를 자동 실행하지 않습니다.

빌드 설정은 [Docker 공식 GitHub Actions 문서](https://docs.docker.com/build/ci/github-actions/)를 기준으로 구성했습니다.

## 로컬 검증 결과 (2026-09-08)

- prod Docker 이미지 빌드 성공.
- 외부 네트워크 차단 상태에서 테스트 38개·검증 3,533건 통과.
- 운영 이미지의 random-v1 5게임 미리보기 성공, 저장하지 않음.
- 운영 이미지의 `.env`·로컬 인증서·Git 디렉터리 제외 및 일반 사용자 실행 확인.
- YAML 파싱과 Git 공백 검사 통과.
- GitHub Actions 실행과 GHCR 업로드는 아직 수행하지 않음. 첫 push 이후 원격 실행 결과를 확인해야 함.
