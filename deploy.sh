#!/usr/bin/env bash
set -Eeuo pipefail

# 서버에는 이 스크립트와 compose.prod.yml, 운영 .env만 준비하면 됩니다.
project_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
cd "$project_dir"
if [[ $# -ne 1 || ! "$1" =~ ^[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,127}$ ]]; then
    echo "사용법: bash deploy.sh <이미지 태그> (예: sha-전체커밋SHA 또는 main)" >&2
    exit 1
fi
command -v docker >/dev/null
command -v flock >/dev/null || { echo "동시 배포 방지를 위해 util-linux의 flock이 필요합니다." >&2; exit 1; }
[[ -f .env && -f compose.prod.yml ]] || { echo "운영 .env와 compose.prod.yml을 먼저 준비하세요." >&2; exit 1; }
exec 9> .deploy.lock
flock -n 9 || { echo "다른 배포가 진행 중입니다." >&2; exit 1; }
docker compose version >/dev/null
image="ghcr.io/hazelworksmaster/luckydive-generator:$1"
export GENERATOR_IMAGE="$image"
compose=(docker compose --env-file /dev/null --project-directory "$project_dir" -p luckydive-generator-prod -f "$project_dir/compose.prod.yml")

# 다운로드와 CLI 검증이 실패하면 기존 컨테이너를 그대로 유지합니다.
echo "이미지 다운로드: $image"
docker pull "$image"
new_image=$(docker image inspect --format '{{.Id}}' "$image")
docker run --rm --network none "$new_image" php artisan lotto:generate --algorithm=random-v1 --count=1 --dry-run >/dev/null

# 호스트 저장소는 배포 사이에 유지하며 필요한 디렉터리의 실행 권한만 준비합니다.
mkdir -p storage
docker run --rm --network none --user root --entrypoint sh \
    --volume "$project_dir/storage:/app/storage" "$new_image" -eu -c '
    for dir in app app/private app/public framework framework/cache framework/cache/data framework/sessions framework/views logs; do
        mkdir -p "/app/storage/$dir"
        chown www-data:www-data "/app/storage/$dir"
    done
    '
docker run --rm --network none --env-file .env --env APP_ENV=production --env APP_DEBUG=false \
    --volume "$project_dir/storage:/app/storage" "$new_image" php artisan list --raw >/dev/null

old_container=$("${compose[@]}" ps --all --quiet app)
old_image=""
if [[ -n "$old_container" ]]; then
    [[ "$old_container" != *$'\n'* ]] || { echo "app 컨테이너가 여러 개여서 자동 교체를 중단합니다." >&2; exit 1; }
    old_image=$(docker inspect --format '{{.Image}}' "$old_container")
fi

# 변경 가능한 태그 대신 내려받아 검증한 이미지 ID로 교체 및 복구합니다.
rollback() {
    local result=$?
    trap - ERR INT TERM
    echo "새 컨테이너 기동에 실패했습니다." >&2
    if [[ -n "$old_image" ]]; then
        export GENERATOR_IMAGE="$old_image"
        if "${compose[@]}" up -d --no-build --pull never --force-recreate --wait --wait-timeout 90 app; then
            echo "이전 이미지로 복구했습니다: $old_image" >&2
        else
            echo "이전 이미지 복구도 실패했습니다. 컨테이너 상태와 로그를 확인하세요." >&2
        fi
    else
        "${compose[@]}" stop --timeout 60 app || true
        echo "첫 배포이므로 복구할 이전 이미지가 없습니다." >&2
    fi
    exit "${result:-1}"
}
trap rollback ERR
trap 'false' INT TERM
export GENERATOR_IMAGE="$new_image"
"${compose[@]}" stop --timeout 60 app
"${compose[@]}" up -d --no-build --pull never --force-recreate --wait --wait-timeout 90 app
trap - ERR INT TERM
echo "배포 완료: $image ($new_image)"
echo "명령 실행: docker compose -p luckydive-generator-prod -f compose.prod.yml exec app php artisan list"
