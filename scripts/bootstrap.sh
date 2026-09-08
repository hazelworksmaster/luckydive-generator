#!/usr/bin/env sh
set -eu
# 환경 파일·의존성·앱 키만 준비하고 DB 생성과 migration은 실행하지 않습니다.
project_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$project_dir"
if [ ! -f .env ]; then cp .env.example .env; fi
mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
./scripts/docker.sh local build
./scripts/docker.sh local run --rm app composer install --no-interaction --prefer-dist
if grep -q '^APP_KEY=$' .env; then ./scripts/docker.sh local run --rm app php artisan key:generate --ansi; fi
