# syntax=docker/dockerfile:1
FROM node:24-alpine@sha256:ebfe2f90462722a7a4de65e91990e97fe0d401c70e0e762c5b53302f905ec1c1 AS frontend_build
WORKDIR /frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer_upstream

FROM dunglas/frankenphp:1-php8.5@sha256:a052ae7cccd4a025eca17767e7acac57a4fc660a4f3dfc790be9b9cd5182d6e6 AS php_base
WORKDIR /app
RUN install-php-extensions pdo_pgsql intl redis-6.3.0 pcntl \
    && useradd --uid 1000 --create-home app \
    && mkdir -p /data/caddy /config/caddy /app/var \
    && chown -R app:app /data /config /app
COPY --from=composer_upstream /usr/bin/composer /usr/local/bin/composer
COPY docker/php/app.ini /usr/local/etc/php/conf.d/99-app.ini
COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
COPY docker/caddy/ /etc/frankenphp/
ENV PHP_MEMORY_LIMIT=128M
ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile.prod"]
HEALTHCHECK --interval=10s --timeout=5s --start-period=30s --retries=3 CMD php /usr/local/bin/healthcheck.php
COPY docker/php/healthcheck.php /usr/local/bin/healthcheck.php
COPY docker/php/worker-healthcheck.php /usr/local/bin/worker-healthcheck.php

FROM php_base AS php_builder
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

FROM php_builder AS dev
ENV APP_ENV=dev APP_DEBUG=1 COMPOSER_ALLOW_SUPERUSER=1
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-interaction --no-progress --no-scripts
COPY . .
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile.dev"]

FROM php_builder AS backend_build
ENV APP_ENV=prod APP_DEBUG=0 COMPOSER_ALLOW_SUPERUSER=1
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-dev --no-interaction --no-progress --no-scripts --no-autoloader
COPY bin/ bin/
COPY config/ config/
COPY migrations/ migrations/
COPY public/ public/
COPY src/ src/
RUN composer dump-autoload --classmap-authoritative --no-dev --no-scripts
# No credentials or dependency connections are needed to compile the container.
RUN APP_SECRET=build-only-placeholder APP_RUNTIME_OPTIONS='{"disable_dotenv":true}' \
    DATABASE_URL='postgresql://build:build@127.0.0.1:1/build?serverVersion=18&charset=utf8' \
    REDIS_URL='redis://127.0.0.1:1' MESSENGER_TRANSPORT_DSN='doctrine://default?auto_setup=false' \
    php bin/console cache:warmup --env=prod --no-debug

FROM php_base AS prod
ENV APP_ENV=prod APP_DEBUG=0 APP_RUNTIME_OPTIONS='{"disable_dotenv":true}'
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && rm /usr/local/bin/composer
COPY docker/php/prod.ini /usr/local/etc/php/conf.d/100-prod.ini
COPY --from=backend_build --chown=app:app /app /app
COPY --from=frontend_build --chown=app:app /frontend/dist /app/frontend-dist
USER app
