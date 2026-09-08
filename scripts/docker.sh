#!/usr/bin/env sh
set -eu
# 호출 위치와 관계없이 이 프로젝트의 독립 Compose 스택을 사용합니다.
project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
environment=${1:-local}
case "$environment" in local|prod) ;; *) echo "환경은 local 또는 prod로 지정하세요." >&2; exit 1 ;; esac
if [ "$#" -gt 0 ]; then shift; fi
exec docker compose --project-directory "$project_dir" -p "luckydive-generator-$environment" -f "$project_dir/compose.$environment.yml" "$@"
