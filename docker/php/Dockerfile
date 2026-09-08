# syntax=docker/dockerfile:1
FROM php:8.4.22-cli-alpine3.22 AS base

# 번호 계산과 외부 MySQL 연결에 필요한 최소 확장을 설치합니다.
RUN apk add --no-cache icu-dev oniguruma-dev libzip-dev git unzip \
    && docker-php-ext-install bcmath intl mbstring pdo_mysql zip
COPY --from=composer:2.8.12 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
ENV COMPOSER_HOME=/tmp/composer

FROM base AS local
CMD ["php", "artisan", "list"]

FROM base AS prod
COPY . .
# 비밀 파일은 빌드에서 제외하며 DB 변경 스크립트는 실행하지 않습니다.
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php", "artisan", "list"]
