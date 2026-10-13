#!/bin/sh
set -eu
# Migrations run in their own service, never inside a web request or worker boot.
mkdir -p /app/var/cache /app/var/log
exec docker-php-entrypoint "$@"
