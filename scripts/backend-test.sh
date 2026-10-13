#!/bin/sh
set -eu
# Per-run isolated database; even a failing suite removes its own test database.
test_token="$(date +%s)_$$"
console() {
    docker compose exec -T -e APP_ENV=test -e APP_DEBUG=1 -e TEST_TOKEN="$test_token" backend php bin/console "$@"
}
cleanup() {
    console doctrine:database:drop --force --if-exists --no-interaction
}
trap cleanup EXIT HUP INT TERM
console cache:clear --no-warmup
console doctrine:database:create --no-interaction
console doctrine:migrations:migrate --no-interaction
docker compose exec -T -e APP_ENV=test -e APP_DEBUG=1 -e TEST_TOKEN="$test_token" -e RUN_INFRASTRUCTURE_TESTS=1 backend composer test
