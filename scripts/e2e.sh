#!/usr/bin/env bash
# Reproducible browser E2E against an isolated stack:
#   - a disposable test database sport_e_test<token> on the running postgres
#   - a one-off FrankenPHP container (APP_ENV=test) on E2E_BACKEND_PORT
#   - a host Vite dev server on E2E_VITE_PORT proxying /api to it
# Everything started here is stopped and every resource dropped in the trap;
# the dev database, dev volumes and the core stack are never touched.
set -euo pipefail
cd "$(dirname "$0")/.."

# Preflight: this script needs host tooling (a host Vite dev server and host
# Playwright) plus a system Chromium. Fail before touching any DB/container.
missing=""
for tool in node npm npx curl docker nc; do
    command -v "$tool" >/dev/null 2>&1 || missing="$missing $tool"
done
if [ -n "$missing" ]; then
    echo "E2E requires host tools missing from PATH:$missing." >&2
    echo "Install them (Node.js 24 includes node/npm/npx) and retry." >&2
    exit 1
fi

if [ ! -x frontend/node_modules/.bin/vite ] || [ ! -d frontend/node_modules/@playwright/test ]; then
    echo "Frontend dependencies are missing: run 'cd frontend && npm ci' first." >&2
    exit 1
fi

if [ -z "${E2E_CHROMIUM:-}" ]; then
    for candidate in \
        "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
        "/opt/homebrew/bin/chromium" \
        "/Applications/Chromium.app/Contents/MacOS/Chromium"; do
        if [ -x "$candidate" ]; then
            E2E_CHROMIUM="$candidate"
            break
        fi
    done
fi
if [ -z "${E2E_CHROMIUM:-}" ] || [ ! -x "$E2E_CHROMIUM" ]; then
    echo "No usable Chromium-family browser found." >&2
    echo "Install Chrome/Chromium or set E2E_CHROMIUM to an executable browser binary." >&2
    exit 1
fi
export E2E_CHROMIUM

TEST_TOKEN="e2e$(date +%s)$$"
BACKEND_PORT="${E2E_BACKEND_PORT:-18080}"
VITE_PORT="${E2E_VITE_PORT:-15173}"
CONTAINER="sport-e-e2e-${TEST_TOKEN}"
VAR_VOLUME="sport-e-e2e-var-${TEST_TOKEN}"
LOG_DIR="$(mktemp -d -t sport-e-e2e)"
VITE_PID=""

console() {
    docker compose exec -T -e APP_ENV=test -e APP_DEBUG=1 -e TEST_TOKEN="$TEST_TOKEN" \
        backend php bin/console "$@"
}

status=0
cleanup() {
    status=$?
    set +e
    if [ -n "$VITE_PID" ]; then
        kill "$VITE_PID" 2>/dev/null
        wait "$VITE_PID" 2>/dev/null
    fi
    pkill -f "vite.*--port $VITE_PORT" 2>/dev/null
    if [ "$status" -ne 0 ]; then
        echo "--- vite log (last 30 lines) ---" >&2
        tail -30 "$LOG_DIR/vite.log" 2>/dev/null >&2
        echo "--- e2e backend log (last 30 lines) ---" >&2
        docker logs "$CONTAINER" 2>&1 | tail -30 >&2
    fi
    docker rm -f "$CONTAINER" >/dev/null 2>&1
    docker volume rm "$VAR_VOLUME" >/dev/null 2>&1
    console doctrine:database:drop --force --if-exists --no-interaction >/dev/null 2>&1
    rm -rf "$LOG_DIR"
    exit "$status"
}
trap cleanup EXIT HUP INT TERM

for port in "$BACKEND_PORT" "$VITE_PORT"; do
    if nc -z 127.0.0.1 "$port" >/dev/null 2>&1; then
        echo "Port $port is already in use; set E2E_BACKEND_PORT/E2E_VITE_PORT." >&2
        exit 1
    fi
done

echo "==> Creating disposable test database (token $TEST_TOKEN)"
console doctrine:database:create --no-interaction
console doctrine:migrations:migrate --no-interaction --allow-no-migration
console doctrine:fixtures:load --no-interaction

BACKEND_ID="$(docker compose ps -q backend)"
IMAGE="$(docker inspect -f '{{.Config.Image}}' "$BACKEND_ID")"
NETWORK="$(docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{end}}' \
    "$BACKEND_ID" | awk '{print $1}')"
VENDOR_VOLUME="$(docker inspect -f \
    '{{range .Mounts}}{{if eq .Destination "/app/vendor"}}{{.Name}}{{end}}{{end}}' "$BACKEND_ID")"
DATABASE_URL="$(docker compose exec -T backend printenv DATABASE_URL | tr -d '\r')"
REDIS_URL="$(docker compose exec -T backend printenv REDIS_URL | tr -d '\r')"
APP_SECRET="$(docker compose exec -T backend printenv APP_SECRET | tr -d '\r')"
MESSENGER_TRANSPORT_DSN="$(docker compose exec -T backend printenv MESSENGER_TRANSPORT_DSN | tr -d '\r')"

echo "==> Starting one-off test backend $CONTAINER on 127.0.0.1:$BACKEND_PORT"
# Same bind mounts as compose.override.yaml: source from the host, vendor
# from the shared image-cache volume, an isolated var volume for test caches.
docker run -d --name "$CONTAINER" --network "$NETWORK" --init \
    -e APP_ENV=test -e APP_DEBUG=1 -e "TEST_TOKEN=$TEST_TOKEN" \
    -e "DATABASE_URL=$DATABASE_URL" -e "REDIS_URL=$REDIS_URL" \
    -e "APP_SECRET=$APP_SECRET" -e "MESSENGER_TRANSPORT_DSN=$MESSENGER_TRANSPORT_DSN" \
    -e MAILER_DSN=null://null -e APP_PUBLIC_URL="http://127.0.0.1:$VITE_PORT" \
    -e FRANKENPHP_WORKERS=1 -e FRANKENPHP_MAX_THREADS=4 \
    -v "$PWD:/app" -v "$VENDOR_VOLUME:/app/vendor" -v "$VAR_VOLUME:/app/var" \
    -p "127.0.0.1:$BACKEND_PORT:8080" \
    "$IMAGE" >/dev/null

echo "==> Waiting for the test backend"
for _ in $(seq 1 60); do
    if curl -fsS "http://127.0.0.1:$BACKEND_PORT/api/health/live" >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
curl -fsS "http://127.0.0.1:$BACKEND_PORT/api/health/live" >/dev/null
curl -fsS "http://127.0.0.1:$BACKEND_PORT/api/v1/context" >/dev/null

echo "==> Starting Vite on 127.0.0.1:$VITE_PORT (proxy -> $BACKEND_PORT)"
API_PROXY_TARGET="http://127.0.0.1:$BACKEND_PORT" \
    "$PWD/frontend/node_modules/.bin/vite" frontend \
    --port "$VITE_PORT" --strictPort --host 127.0.0.1 \
    >"$LOG_DIR/vite.log" 2>&1 &
VITE_PID=$!

echo "==> Waiting for Vite"
for _ in $(seq 1 60); do
    if curl -fsS "http://127.0.0.1:$VITE_PORT" >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
curl -fsS "http://127.0.0.1:$VITE_PORT" >/dev/null

echo "==> Running Playwright"
(
    cd frontend
    E2E_BASE_URL="http://127.0.0.1:$VITE_PORT" npx playwright test
)
