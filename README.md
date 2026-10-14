# Sport E — Symfony 8.1 + React storefront

[Russian version](README.ru.md)

A working online store: a Symfony 8.1 API at the repository root, a React + TypeScript SPA in `frontend/`, PostgreSQL,
Redis cache, and a Symfony Messenger queue. The local environment runs via Docker Compose; production is built as a
separate multi-stage FrankenPHP/Caddy image.

Line-by-line blueprint migration status and verification evidence are in
[docs/IMPLEMENTATION_STATUS.md](docs/IMPLEMENTATION_STATUS.md). Architectural decisions are in
[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), the API contract is in [openapi/openapi.yaml](openapi/openapi.yaml).

## Features

- Market context: EUR/BYN/PLN currencies, en/ru/pl locales, VAT by delivery country, country detection via GeoIP.
- Localized catalog: category tree, lists with filters/sorting/pagination, product cards with variants.
- Guest and user carts with merge on login; transactional checkout with stock reservation and `SP-*` orders.
- Identity: registration, login, email verification, password reset and confirmation, profile with addresses and orders.
- Administrative order status transitions with stock release on cancellation.
- Exchange rates: string-safe updates through source chains (BYN, PLN) with stale fallback.
- Storefront cache: SWR envelopes with generation rotation, stampede locking, and degradation when Redis is unavailable.
- Symfony Scheduler + Messenger: hourly rate refresh, cache warming every 5 minutes, asynchronous mail and
  invalidation.
- Health endpoints and an OpenAPI contract kept in sync with routes by a contract test.

### Deferred scope

Shop Pay (payment session/attempt handling), the inbound Shopify webhook, and payment reconciliation have been
**deferred by user decision dated 2026-10-13** and are not implemented. The neutral payment foundation — schema,
entities, fixtures, and `SodiumFieldCipher` — is retained and covered by tests. Production must set a generated
`APP_FIELD_CIPHER_KEY` before enabling payment services. Details and the status matrix:
[docs/IMPLEMENTATION_STATUS.md](docs/IMPLEMENTATION_STATUS.md).

## Versions and requirements

Running the project requires Docker Engine/Docker Desktop with Linux containers, Docker Compose v2 with `up --wait`
support, Git, and a POSIX shell. The Docker daemon must be running. `make` is an optional wrapper around the executable
`./dev`. The main Docker workflow (`init`, `up`, `test`, `lint`, `build`) requires no local PHP, Composer, Node.js, or
PostgreSQL: day-to-day commands run inside containers. The first run downloads images and dependencies from public
registries, so it requires network access.

Browser E2E (`./dev e2e`) is the only exception: `scripts/e2e.sh` runs Vite and Playwright on the host and uses the
system Chromium/Chrome. It requires local Node.js 24, `cd frontend && npm ci` (dependencies on the host, not in a Docker
volume), the POSIX utilities `curl` and `nc`, and an executable Chromium-family browser — an installed Chrome/Chromium
or a binary path in the `E2E_CHROMIUM` variable. The script checks all of this in preflight before creating the database
and containers.

Ports 5173, 8080, and 8443 must be free on the machine; they can be changed in the local `.env`. The runtime supports
amd64/arm64 within the platforms of the published base images. The web container is limited to 1 CPU and 768 MB by
default; PostgreSQL, Redis, Messenger, and Vite require additional Docker VM memory.

| Component | Pinned version |
| --- | --- |
| PHP in the runtime image | 8.5.11 |
| FrankenPHP / Caddy | 1.13.1 / 2.11.7 |
| Symfony FrameworkBundle | 8.1.8; direct Symfony packages constrained to `8.1.*` |
| Symfony Runtime | 8.1.0, with native FrankenPHP worker mode |
| Doctrine ORM / DBAL | 3.7.5 / 4.5.0 |
| DoctrineBundle / DoctrineMigrationsBundle | 3.3.2 / 3.7.0 |
| MonologBundle / Monolog | 4.1.0 / 3.12.1 |
| PostgreSQL / Redis | 18.6 / 8.10.2 |
| Composer image | 2.10.3 |
| Node.js | 24.21.0; frontend requires `>=24.15.0 <25` |
| React / React Router | 19.3.0 / 8.4.0 |
| Vite / TypeScript | 8.3.4 / 6.0.3 |
| Tailwind CSS / official Vite plugin | 4.3.3 / 4.3.3 |
| TanStack Query | 5.104.2 |
| PHPUnit / PHPStan / PHP-CS-Fixer | 13.4.2 / 2.3.1 / 3.95.27 |
| Vitest / ESLint / Prettier | 5.0.4 / 10.12.0 / 3.9.10 |
| Playwright | @playwright/test 1.64.0 (dev dependency; system Chromium) |

PHP 8.5 was chosen as the compatible stable version and environment for the current dev tools. Exact transitive
dependencies live in `composer.lock` and `frontend/package-lock.json`; base Docker images are pinned by digest in the
Dockerfile and Compose. For reproducible installs use `composer install` and `npm ci`, not `update`.

## One-command first run

From the root of a fresh checkout:

```sh
./dev init
```

The command copies `.env.example` to the Git-ignored `.env` file, generates a random `APP_SECRET`, builds the dev
images, installs dependencies into Docker volumes, and starts the environment while waiting for health checks. An
existing `.env` is preserved. PostgreSQL/Redis start first, a separate `migrate` service applies the versioned
migrations, then backend, worker, and Vite start.

The PostgreSQL password from `.env.example` is intended for local development only; no secrets need to be entered
manually for `./dev init`. `.env`, dependencies, runtime cache, certificates, and backups are excluded from Git.
PostgreSQL and Redis do not publish ports to the host.

### Demo data (dev/test only)

After the first run the database is empty. On a **new or empty dev database**, load the deterministic fixtures:

```sh
./dev console doctrine:fixtures:load --no-interaction
```

Warning: `doctrine:fixtures:load` **completely purges the database** before loading. Run it only in dev/test;
fixtures are never applied in production. The data set and behavior are described in
[docs/DEVELOPMENT_DATA.md](docs/DEVELOPMENT_DATA.md).

| Email | Password | Role |
| --- | --- | --- |
| `customer@example.test` | `SportE!2026` | Customer, email verified |
| `unverified@example.test` | `SportE!2026` | Customer, email not verified |
| `admin@example.test` | `SportE!2026` | Administrator |

With a working `make` you can use `make init`: the Makefile invokes the same command. On macOS the system `make` may
refuse to work until the Xcode license is accepted. In the verified environment this limitation is present; `./dev`
lets you run the project and all checks without changing system settings or accepting the license.

| Address | Purpose |
| --- | --- |
| [http://localhost:5173](http://localhost:5173) | Storefront: `/`, `/shop`, `/cart`, `/checkout`, `/orders`, `/admin/orders` |
| [http://localhost:8080/api/health/live](http://localhost:8080/api/health/live) | Liveness |
| [http://localhost:8080/api/health/ready](http://localhost:8080/api/health/ready) | PostgreSQL + Redis readiness |
| [http://localhost:8080/api/version](http://localhost:8080/api/version) | Application name, environment, build version |
| [https://localhost:8443/api/health/live](https://localhost:8443/api/health/live) | Local HTTPS backend |

The frontend uses a relative `/api`; Vite proxies it to `http://backend:8080`. No CORS is required.

Checking container state:

```sh
docker compose ps
curl --fail http://localhost:8080/api/health/live
curl --fail http://localhost:8080/api/health/ready
```

A successful liveness returns `{"status":"ok"}` without touching the database or Redis. Readiness actually performs a
PostgreSQL `SELECT 1` and a Redis `PING`: when a dependency is unavailable it returns HTTP 503 and `checks` states.
An unknown `/api/*` route returns HTTP 404 in the `{"error":{"code":"not_found","message":"Not Found"}}` format.
Internal exceptions, DSNs, and secrets never reach API responses.

### Trusting the local HTTPS

Caddy creates a local certificate in the `caddy_data` volume; automatic CA installation on the host is disabled. HTTP
is fine for ordinary development and the Vite proxy. If you need HTTPS in a browser, export only the public root
certificate and add it to your system's trusted certificates:

```sh
mkdir -p certificates
docker compose cp backend:/data/caddy/pki/authorities/local/root.crt certificates/caddy-local-root.crt
```

For example, on macOS:

```sh
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain certificates/caddy-local-root.crt
```

On other OSes use the standard trusted-CA store; some browsers have a separate store. Do not export the CA private key.
The `certificates/` directory is Git-ignored. Deleting `caddy_data` creates a new CA and the previous trust must be
refreshed. Caddy provides HTTP/2 and HTTP/3; HTTP/3 also requires an available UDP port.

## Structure

```text
.
├── bin/                       # Symfony console
├── config/                    # Routes, services, Doctrine, Cache, Messenger, Scheduler
├── migrations/                # Foundation migration + 4 business-schema migrations
├── openapi/openapi.yaml       # API contract; a contract test keeps it in sync
├── public/                    # PHP entrypoint
├── src/
│   ├── Domain/                # Cart, Catalog, Commerce, Identity, Ordering, Payment
│   ├── Application/           # Use cases, ports, read models, Messenger messages
│   ├── Infrastructure/        # DBAL stores, cache, crypto, source HTTP clients
│   ├── Presentation/          # HTTP controllers, ApiProblem, security handlers
│   ├── Controller/            # Health/version controllers
│   ├── Command/               # storefront:cache-*, commerce:refresh-exchange-rates
│   └── DataFixtures/          # Deterministic dev/test fixtures
├── tests/                     # Unit, functional, contract and concurrency checks
├── frontend/
│   ├── src/pages/             # Feature pages: storefront, checkout, account, admin
│   ├── src/api/               # API clients on top of the shared typed client
│   └── e2e/                   # Playwright browser scenarios
├── blog-api/                  # Legacy application (read-only source; do not modify)
├── docker/                    # Caddy, PHP, frontend dev image
├── scripts/                   # Env init, isolated test DB, browser E2E
├── docs/                      # Architecture, status, migration plan, dev data
├── compose.yaml               # Base services and production runtime
├── compose.override.yaml      # Automatically applied dev configuration
├── compose.prod.yaml          # Production port mappings
├── Dockerfile                 # Dev target and multi-stage production build
├── .env.example               # Local values and required ENV vars
├── dev                        # Executable command interface without a make dependency
├── Makefile
├── composer.json
└── composer.lock
```

Symfony lives at the root; only the frontend is a separate application. In the runtime image Symfony sits in `/app`,
the production SPA in `/app/frontend-dist`. Caddy routes `/api` to `/app/public/index.php`, serves assets directly, and
applies an `index.html` fallback to frontend routes. Unknown API routes are not swallowed by the SPA.

## Developer commands

Commands run from the root and use the local Compose setup. The Makefile provides same-named wrappers (`make up`,
`make test`, and others):

| Command | Action |
| --- | --- |
| `./dev init` | Prepare a fresh checkout and start the whole environment |
| `./dev up` | Rebuild changed dev images and start services |
| `./dev down` | Stop services; keep persistent volumes |
| `./dev logs` | Aggregate logs |
| `./dev test` | Backend tests on a separate database, then frontend tests |
| `./dev e2e` | Browser E2E in an isolated test environment (`./scripts/e2e.sh`; requires host Node + Chromium) |
| `./dev lint` | Composer, PHPStan, PHP-CS-Fixer, Symfony lint, Doctrine, TS/ESLint/Prettier |
| `./dev format` | Fix backend and frontend formatting |
| `./dev build` | Verify the frontend production build and build the production image |
| `./dev migrate` | Apply migrations via the dedicated service |
| `./dev console debug:router` | Symfony console (`make console ARGS='debug:router'`) |
| `./dev shell` | Shell into the backend container |
| `./dev worker-logs` | Messenger consumer logs |
| `./dev worker-start` / `./dev worker-stop` | Start / stop the worker |
| `./dev failed-show` | Inspect the failure queue |
| `./dev failed-retry ID --force` | Resend a selected failed message |

Application commands (via `./dev console`):

```sh
./dev console storefront:cache-warm --pages=2 --locale=en   # warm the storefront cache
./dev console storefront:cache-clear                      # rotate all cache groups
./dev console commerce:refresh-exchange-rates             # rates; makes REAL external requests
./dev console debug:scheduler                             # recurring messages schedule
./dev console debug:messenger                             # routing and handlers
```

`./dev down` does not delete PostgreSQL data. The `down --volumes` and `volume rm` commands do delete data; they are
not needed for a normal stop. After changing lock files, rerun `./dev init` to refresh dependencies in the named
volumes.

## Environment and timeouts

`.env.example` lists the required variables. `.env` serves the local Compose setup and Symfony; production uses a
separate ignored `.env.prod.local` or an external secrets store. In production dotenv is disabled and Symfony receives
real environment variables from Compose.

| Variables | Purpose / local values |
| --- | --- |
| `APP_SECRET`, `APP_NAME`, `BUILD_VERSION` | Application secret, display name, build identifier |
| `APP_ENV`, `APP_DEBUG` | Dev: `dev` / `1`; production runtime: `prod` / `0` |
| `APP_PUBLIC_URL` | Public origin for links in emails; locally `http://localhost:5173` |
| `MAILER_DSN`, `MAILER_FROM` | Mail transport (`null://null` locally) and sender address |
| `COMPOSE_PROJECT_NAME` | Isolation of service, image, and volume names |
| `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD` | Database and credentials; local placeholders |
| `DATABASE_URL` | DSN for running Symfony directly; Compose builds it from `POSTGRES_*` |
| `POSTGRES_MAX_CONNECTIONS` | PostgreSQL total server limit: 50 |
| `DB_CONNECTION_TIMEOUT`, `DB_STATEMENT_TIMEOUT_MS` | Connect 2 seconds; SQL statement timeout 3000 ms |
| `REDIS_URL` | Application cache provider: `redis://redis:6379` |
| `REDIS_CONNECT_TIMEOUT`, `REDIS_READ_TIMEOUT` | 1 second each |
| `REDIS_MAXMEMORY` | Redis memory budget: `128mb` |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=false` |
| `MESSENGER_TIME_LIMIT`, `MESSENGER_MEMORY_LIMIT` | Consumer rotation: 3600 seconds / soft memory limit |
| `COMMERCE_DEFAULT_COUNTRY`, `GEOIP_URL` | Default country `DE`; GeoIP endpoint with `{ip}` placeholder |
| `MYFIN_EUR_BYN_URL` | BYN rate source (Myfin HTML) |
| `WALUTOMAT_EUR_PLN_URL`, `WALUTOMAT_API_TOKEN` | PLN rate source and its bearer token; empty — falls back to NBP |
| `DELIVERY_STANDARD_NET_CENTS`, `DELIVERY_EXPRESS_NET_CENTS` | Delivery rates in net cents: 590 / 1290 |
| `DELIVERY_FREE_SHIPPING_GROSS_EUR_CENTS` | Free delivery threshold: 5000 gross EUR cents |
| `STOREFRONT_CACHE_ENABLED` | Enable the storefront cache; locally `1` |
| `STOREFRONT_CACHE_PREFIX`, `STOREFRONT_CACHE_GENERATION_TTL` | Key prefix `sport_e`; generation TTL 2592000 |
| `STOREFRONT_CACHE_{TREE,LIST,CARD}_{FRESH,TOTAL}_TTL` | Fresh/total TTL per cache group |
| `EXCHANGE_RATE_CACHE_TTL` | Exchange-rate pool TTL, maximum 300 seconds |
| `LOCK_DSN` | `flock` on a single host; multi-replica needs a shared lock store |
| `APP_FIELD_CIPHER_KEY` | Base64 32-byte `SodiumFieldCipher` key; foundation of the deferred payments |
| `FRANKENPHP_WORKERS`, `FRANKENPHP_MAX_THREADS` | Two web workers; total maximum PHP threads — four |
| `PHP_MEMORY_LIMIT`, `WORKER_PHP_MEMORY_LIMIT` | PHP memory limits for web / CLI consumer |
| `WEB_CPUS`, `WEB_MEMORY_LIMIT`, `WORKER_MEMORY_LIMIT` | Container resource limits |
| `TRUSTED_PROXIES`, `TRUSTED_HOSTS` | Permitted proxy IP/CIDR and host regex |
| `HTTP_PORT`, `HTTPS_PORT`, `FRONTEND_PORT` | Local host ports: 8080 / 8443 / 5173 |
| `PUBLIC_HTTPS_PORT` | Public HTTPS port for HTTP redirect; locally 8443, production 443 |
| `SERVER_NAME` | Caddy domain: locally `localhost`, in production a real DNS hostname |

Compose maps `DB_CONNECTION_TIMEOUT` to the libpq `PGCONNECT_TIMEOUT`, and `DB_STATEMENT_TIMEOUT_MS` to
`PGOPTIONS=-c statement_timeout=...`. The limits therefore also apply to direct Doctrine connections. When running PHP
outside Compose, these libpq variables must be set manually. Total readiness duration may include sequential checks of
several dependencies; the endpoint does not promise a single three-second deadline.

An empty `TRUSTED_PROXIES` means proxy headers are not trusted. When adding a reverse proxy/CDN, specify its exact
addresses or CIDRs and keep the host allowlist. Arbitrary trust of all clients or `TRUSTED_HOSTS=.*` is not required.
For direct local access to PostgreSQL/Redis use a separate override bound to `127.0.0.1`; the default Compose setup
keeps them on their internal network.

## PostgreSQL, migrations, and Redis

PostgreSQL is the only primary relational database. `postgres_data` stores the data; the volume path matches
PostgreSQL 18. The foundation migration creates `messenger_messages`; the four subsequent migrations add the
normalized business schema — 23 domain, auth, session, and integration tables. Doctrine also maintains the internal
`doctrine_migration_versions` table. The schema was created from scratch; no ETL from the legacy system was performed.
Fixtures are intended for dev/test only.

`migrate` runs before web starts. `auto_setup=false` prevents Messenger from creating its table on a hot request or
consumer start. To inspect and apply versions:

```sh
./dev console doctrine:migrations:status
./dev migrate
./dev console doctrine:migrations:up-to-date
```

Production uses these versioned migrations. `doctrine:schema:update --force` is not part of deployment. Migration
compatibility with the old and new application versions must be considered during a rolling deployment.

`cache.app` uses Redis with short network timeouts. `cache.system` keeps the standard Symfony adapter. The storefront
cache and exchange-rate cache are separate Redis pools from `config/packages/storefront_cache.yaml`. Redis is used
only for recoverable cache: a 128 MB memory limit, `allkeys-lru` eviction, AOF and snapshots disabled. A Redis restart
may clear the cache; the catalog and rates then degrade to direct database queries rather than errors. Critical queues
and durable data are not stored in this Redis.

## Messenger worker and Scheduler

`async` and `failed` are separate queue names in the PostgreSQL `messenger_messages` table. The main transport has
three retries with delays starting at one second; once retries are exhausted the message moves to the failure
transport. `async` routes `SendEmailMessage` (asynchronous mail), `RefreshCatalogCache` (storefront cache
stale-refresh), `WarmStorefrontCache`, and `RefreshExchangeRates`.

The `default` schedule (`StoreSchedule`) publishes to `scheduler_default`: `0 * * * *` — `RefreshExchangeRates`,
`*/5 * * * *` — `WarmStorefrontCache(2)`; the stateful schedule stores its state in a cache pool and applies
`processOnlyLastMissedRun`. A single worker consumes `scheduler_default` and `async` at the same time.

The consumer runs as a separate service with `init`, `pcntl`, signal passing via `exec`, and `stop_grace_period: 60s`.
Time and memory are limited; `restart: unless-stopped` brings the consumer back after a scheduled rotation. A
container stop sends SIGTERM; long-running handlers must reconcile grace period, redelivery timeout, and idempotency.
Doctrine delivery may repeat, so exactly-once is not claimed; handlers are designed to be idempotent. The worker
health check verifies the consumer process and PostgreSQL.

```sh
./dev worker-logs
./dev console messenger:stats
./dev console debug:scheduler
./dev console debug:messenger
./dev failed-show
./dev failed-show ID -vv
./dev failed-retry ID --force
./dev worker-stop
./dev worker-start
```

Before a retry, investigate the cause of the failure and the consequences of reprocessing. In production the same
console commands run through the explicit production Compose, for example:

```sh
docker compose --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml \
    exec backend php bin/console messenger:failed:show
```

## Quality checks

After the environment is up:

```sh
./dev test
./dev lint
./dev build
./dev e2e
```

The backend uses PHPUnit, PHPStan, PHP-CS-Fixer, `composer validate --strict`, Symfony `lint:yaml`/`lint:container`,
ORM mapping validation, and a check that migrations are up to date. The test script clears the test cache, creates a separate
database with a unique test suffix, applies migrations, and drops it via a cleanup trap even after test failures. The
runtime dev DB is not used as storage for temporary test data. New PHP files use strict types and attributes.

The frontend is checked with TypeScript, ESLint, Prettier, and Vitest/Testing Library; `npm run build` performs
typecheck and the Vite build.

`./dev e2e` (`scripts/e2e.sh`) creates a unique disposable database on the running PostgreSQL, applies migrations and
fixtures, starts a one-off backend container (`APP_ENV=test`) on a free localhost port and a Vite server proxying to
it, then runs Playwright on the system Chromium. All processes, containers, volumes, and the database itself are
removed in a trap; the dev database and main stack are untouched. The script is repeatable and makes no external calls
(the test env uses a fake rate fetcher and a null mail transport).

Unlike the other `./dev` commands, E2E runs Vite and Playwright on the host and therefore requires: local Node.js 24
and `cd frontend && npm ci`, the POSIX utilities `curl`/`nc`, an executable Chrome/Chromium or `E2E_CHROMIUM` pointing
to a binary. Preflight stops the script with a hint before creating the database and containers.

Real command results:

| Check | Status |
| --- | --- |
| Backend suite on a disposable database (`scripts/backend-test.sh`) | Passed: 312 tests, 1713 assertions |
| Backend suite on the host (`composer test`) | Passed: 312 tests, 846 assertions, 42 infra-gated skips |
| `composer lint` (composer validate, PHPStan, YAML, container, mapping) | Passed: PHPStan 0 errors |
| OpenAPI contract test | Passed: 1 test, 99 assertions; routes and contract in sync |
| Frontend: Vitest, lint, typecheck, format, production build | Passed: 61/61 tests; all checks and the Vite build |
| Playwright E2E (`./dev e2e`) | Passed: 4/4 — guest checkout, register→order, admin transition, mobile smoke |
| Migration lifecycle on a disposable database | Passed: latest → `Version20261013000000` → latest; schema in sync |
| Fixture idempotency | Passed: 2 consecutive purge/load runs — identical counts, natural keys and timestamps |

Known caveat: `testConcurrentCheckoutsCannotOversell` relies on the timing of two parallel HTTP requests, like most
PHP concurrency tests; it is stable in runs but theoretically sensitive to load.

## Production build and run

Production is started with **explicit** `-f compose.yaml -f compose.prod.yaml`: this excludes the automatically
applied `compose.override.yaml`, dev bind mounts, Vite, and dev dependency volumes. `./dev prod-build`/`./dev prod-up`
and their Makefile wrappers already use these flags. Do not start production with a plain `docker compose up`.

Prepare a separate ignored environment file:

```sh
cp .env.example .env.prod.local
openssl rand -hex 32
openssl rand -hex 32
openssl rand -base64 32
```

Save the two hex values as `APP_SECRET` and `POSTGRES_PASSWORD`, and the base64 value as `APP_FIELD_CIPHER_KEY`, then
adapt the file for your deployment:

```dotenv
COMPOSE_PROJECT_NAME=sport-e-prod
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=REPLACE_WITH_RANDOM_APP_SECRET
POSTGRES_PASSWORD=REPLACE_WITH_RANDOM_DATABASE_PASSWORD
BUILD_VERSION=REPLACE_WITH_UNIQUE_RELEASE_ID
APP_PUBLIC_URL=https://shop.example.com
MAILER_DSN=REPLACE_WITH_REAL_SMTP_DSN
MAILER_FROM='Sport E <no-reply@shop.example.com>'
APP_FIELD_CIPHER_KEY=REPLACE_WITH_GENERATED_BASE64_32B_KEY
LOCK_DSN=flock
SERVER_NAME=shop.example.com
TRUSTED_HOSTS='^(shop\.example\.com|127\.0\.0\.1)$'
TRUSTED_PROXIES=
HTTP_PORT=80
HTTPS_PORT=443
PUBLIC_HTTPS_PORT=443
```

Placeholder values are replaced before launch. Distinct `COMPOSE_PROJECT_NAME` values separate local and production
volumes; `BUILD_VERSION` must change for each new build. DNS hostname, firewall, and TCP 80/443 must allow Caddy to
obtain an ACME certificate; UDP 443 is needed for HTTP/3. The loopback host is kept in the allowlist for the internal
health check. If an external reverse proxy terminates TLS, its addresses and the public origin must be configured
separately.

`APP_FIELD_CIPHER_KEY` is the encryption key for the neutral payment foundation (deferred scope): before enabling payment
components, production must use a generated key, not the example value. `LOCK_DSN=flock` is correct for a single host;
with multiple web/worker replicas configure a shared lock store (for example, Redis). Storefront cache TTLs can be
overridden with the `STOREFRONT_CACHE_*` variables from `.env.example`.

For `POSTGRES_PASSWORD` it is convenient to use hex from the example: it is URL-safe. In Compose, `DATABASE_URL` is
built as a string from `POSTGRES_*`; special characters in the username/password cannot be embedded in a DSN without
percent encoding. If you need an arbitrary password string, adapt the DSN configuration with correct URL encoding and
a separate real password for PostgreSQL. Do not commit the production env file.

```sh
./dev prod-build
./dev prod-up
```

Equivalent commands without the Makefile:

```sh
docker compose --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml build
docker compose --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml up -d --wait --wait-timeout 180
docker compose --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml ps
```

`migrate` applies migrations before web/worker. A new release ID ensures services update together with the built
image. The build does not connect to PostgreSQL/Redis and does not need production credentials for warmup.

The final image contains the PHP runtime, Symfony production dependencies, and the frontend `dist`; Node.js, frontend
sources, PHPUnit/PHPStan/CS-Fixer, and the Composer CLI are not part of the final runtime. The application runs as the
`app` user (UID 1000), with writable `var` and Caddy volumes. OPcache and realpath cache are enabled, Composer classmap
authoritative, prod cache warmup, and FrankenPHP worker mode; Xdebug and JIT are absent.

Caddy serves hashed `/assets/*` with `Cache-Control: public, max-age=31536000, immutable`, and `index.html` with
`no-cache` revalidation. The API gets `no-store`. Static compression and security headers are configured in Caddy; the
dev configuration keeps Vite HMR working. When PHP code is updated, production workers must be restarted: OPcache
timestamp validation is disabled.

Stopping production keeps volumes:

```sh
./dev prod-down
```

For a different env file use `PROD_ENV=/absolute/path/to/environment-file ./dev prod-up` or `make prod-up
PROD_ENV=/absolute/path/to/environment-file`.

## Worker mode, resources, and future load

FrankenPHP worker mode keeps the PHP process alive between requests. Symfony 8.1 supports it through the native
runtime and resets known state; the project's own services do not keep request/user state — only bounded operational
state (for example, already-emitted log warnings). Do not store the Request, authorization, a transaction, or data
from an individual request in mutable/static fields of long-lived services. Correctness is verified by sequential
requests with different contexts and after errors.

For the lazy Redis provider a `kernel.reset` is registered explicitly: the factory returns the standard resettable
proxy, but the declared `Redis` type hides this interface from autoconfiguration. This prevents keeping a lost
connection after a Redis restart; a regression test verifies readiness and cache reads through the same kernel and
cache service. The native Symfony runtime also automatically refreshes the worker after 500 requests by default.

Starting limits are conservative: two web workers, a maximum of four PHP threads, web PHP memory limit 128 MB against
a container limit of 768 MB and 1 CPU. Messenger gets a separate PHP limit of 256 MB, a soft consumer limit of 128 MB,
and a container limit of 384 MB. FrankenPHP `num_threads` adds regular PHP threads on top of the workers, while
`max_threads` caps their total. The PHP memory limit bounds a single execution, not the whole container;
cache/runtime overhead also consumes memory.

Worker count, PostgreSQL connections, SQL timeout, and Redis memory are refined by measurements on the target VPS.
The PostgreSQL budget accounts for web replicas, consumers, migrations, and maintenance connections; increasing
workers by itself does not speed up the system. Catalog growth will require analyzing schema and queries, indexes,
pagination, import batches, and ORM memory.

Performance testing guidance lives in [tests/performance/README.md](tests/performance/README.md). A minimal k6 smoke makes
three requests with one VU against liveness:

```sh
k6 run tests/performance/health-smoke.js
```

k6 is installed separately. The smoke verifies the HTTP contract, not the store's performance. Run a real load test
separately on an authorized target with realistic scenarios and origin/CDN observability.

## Backups and operational limits

A persistent Docker volume preserves data across restarts but does not replace a backup. Before a real production
launch you must have regular PostgreSQL backups, protected storage off the VPS, retention, and verified restore. The
schema and the Messenger queue live in the same database and are covered by its backup; the Redis cache requires no
backup.

Example of a manual logical backup without publishing the database port:

```sh
mkdir -p backups
backup_file="backups/postgres-$(date -u +%Y%m%dT%H%M%SZ).dump"
docker compose --env-file .env.prod.local -f compose.yaml -f compose.prod.yaml \
    exec -T postgres sh -c 'exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$backup_file"
```

This is an example operation, not an installed backup system. The `backups/` directory is Git-ignored; production
dumps may contain confidential data. Test restores in a separate environment; RPO/RTO, PITR, monitoring, secret
rotation, and rolling deployment are not currently implemented.

Compose is designed for local development and an initial single-host deployment. PostgreSQL high availability,
multi-VPS orchestration, a search index, and CDN caching remain future decisions. External services are not installed
automatically.

## Deferred items and next steps

- Payment lines 13/14/18 of the blueprint (Shop Pay, inbound Shopify webhook, payment reconciliation) were deferred
  by user decision dated 2026-10-13. The neutral foundation is retained; statuses are in
  [docs/IMPLEMENTATION_STATUS.md](docs/IMPLEMENTATION_STATUS.md).
- Before the production launch, real `MAILER_DSN`/`MAILER_FROM`, rate-source credentials
  (`WALUTOMAT_API_TOKEN`), and a review of the `GEOIP_URL` provider are required.
- `APP_FIELD_CIPHER_KEY` in production is generated separately (`openssl rand -base64 32`) before enabling payment
  components.
- `LOCK_DSN=flock` suits a single host; a multi-replica deployment requires a shared lock store.
- The legacy application `blog-api/` remains in the repository as the migration source; removing it requires
  separate explicit approval — do not delete it now.

Documents: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/LEGACY_MIGRATION_PLAN.md](docs/LEGACY_MIGRATION_PLAN.md),
[docs/IMPLEMENTATION_STATUS.md](docs/IMPLEMENTATION_STATUS.md), [docs/DEVELOPMENT_DATA.md](docs/DEVELOPMENT_DATA.md),
[openapi/openapi.yaml](openapi/openapi.yaml).

No pushes or PRs are created automatically.
