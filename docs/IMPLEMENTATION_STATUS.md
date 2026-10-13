# Implementation status — Symfony/React migration

Audit date: **2026-10-13**. This file maps every row of the feature parity matrix
([LEGACY_MIGRATION_PLAN.md §3](LEGACY_MIGRATION_PLAN.md#3-feature-parity-matrix)) to its
actual implementation state and the test/command evidence behind it. It is the
authoritative status record; the plan documents intent and evidence.

Statuses:

- **Implemented + tested** — code exists and is covered by the named tests or commands.
- **Deferred** — consciously out of scope, with rationale. The retained schema,
  entities, fixtures and `SodiumFieldCipher` are *not* deleted (see rows 13/14/18).

## Scope amendment (2026-10-13)

The user explicitly removed the payment-provider slice from scope on **2026-10-13**:
Shop Pay (row 13), the Shopify order webhook (row 14) and Shop Pay reconciliation
(row 18) are **deferred**. Their neutral schema (`payment_sessions`,
`payment_attempts`, `shopify_webhook_deliveries`), ORM entities, fixtures and the
`SodiumFieldCipher` field encryption are kept so the slice can be implemented later
without re-migrating. No payment service, route, UI, command or webhook code is
claimed here, and no parity/acceptance is asserted for these rows.

## Parity matrix status

| # | Feature | Status | Evidence |
|---|---|---|---|
| 1 | Market context + preferences | Implemented + tested | `MarketContextResolver` (now on `ExchangeRateProvider`, `exchange_rate_unavailable` → 503); `tests/Unit/Commerce/MarketContextResolverTest.php`, `GeoIpCountryResolverTest.php`, `tests/Unit/EventSubscriber/ApiCsrfSubscriberTest.php`; live `GET /api/v1/context` verified |
| 2 | Catalog browsing | Implemented + tested | `DbalStorefrontCatalog` behind `CachedStorefrontCatalog`; `tests/Functional/CatalogValidationTest.php`, `tests/Unit/Catalog/CachedStorefrontCatalogTest.php` |
| 3 | Localized product detail | Implemented + tested | `CachedStorefrontCatalog::detail` (card group); `tests/Functional/CatalogValidationTest.php`, `tests/Unit/Catalog/CachedStorefrontCatalogTest.php` |
| 4 | Guest/user cart | Implemented + tested | XOR owner invariant restored; `tests/Unit/Cart/CartManagerTest.php`, `tests/Functional/CartCheckoutFlowTest.php` (guest token, merge on login) |
| 5 | Checkout + order placement | Implemented + tested | `PlaceOrder` transaction with row-locked stock, two-step delivery/VAT rounding, `orders_number_unique` collision retry (3 attempts, managed-user reload); `tests/Functional/CartCheckoutFlowTest.php` (18 tests incl. concurrent oversell + collision retry for guest **and** authenticated carts), `tests/Unit/Ordering/` |
| 6 | Order history + detail | Implemented + tested | `OrderView::owns` = owner OR guest `last_order` marker; `tests/Unit/Ordering/OrderViewTest.php`, `CartCheckoutFlowTest` (guest order survives register+login; foreign user → 404) |
| 7 | Register/login/logout | Implemented + tested | `tests/Functional/IdentityFlowTest.php` (14 tests incl. post-login CSRF regression); session auth, throttle, cart merge; `LoginCsrfRefreshSubscriber` rewrites the advertised CSRF token after the fixation strategy clears it |
| 8 | Email verification | Implemented + tested | `tests/Functional/IdentityFlowTest.php`, `tests/Unit/Identity/VerificationLinkSignerTest.php` |
| 9 | Forgot/reset password | Implemented + tested | `tests/Functional/IdentityFlowTest.php`; generic responses, hashed single-use token |
| 10 | Password confirmation | Implemented + tested | `tests/Functional/IdentityFlowTest.php` |
| 11 | Profile (info/address/password/delete) | Implemented + tested | `tests/Functional/IdentityFlowTest.php` |
| 12 | Admin orders | Implemented + tested | `DbalAdminOrderReadModel` (clamped `page>=1`, `perPage 1..100`), `AdminOrderTransition` (lock→validate→release-once→commit); `tests/Functional/AdminOrdersFlowTest.php`, `tests/Unit/OrderStatusTest.php`, `tests/Unit/Ordering/InventoryReleaseTest.php` |
| 13 | Shop Pay lifecycle | **Deferred — explicit user decision 2026-10-13** | Retained: `payment_sessions`/`payment_attempts` schema + entities + fixtures + `SodiumFieldCipher` (`tests/Unit/SodiumFieldCipherTest.php` still green). No service/route/UI built |
| 14 | Shopify webhook | **Deferred — explicit user decision 2026-10-13** | Retained: `shopify_webhook_deliveries` schema + entity + fixtures. No controller/route built |
| 15 | Storefront caching | Implemented + tested | `StorefrontCache` (generation groups tree/list/card, `{value,fresh_until,expires_at}` envelope, SWR + debounced `RefreshCatalogCache`, lock fill, safe-failure fallback); `CachedStorefrontCatalog` decorator; `tests/Unit/Cache/StorefrontCacheTest.php`, `tests/Unit/Catalog/{CachedStorefrontCatalogTest,CatalogCacheInvalidatorTest,RefreshCatalogCacheHandlerTest}.php`; functional invalidation proven once-only on commit and never on rollback (`RecordingCatalogCacheInvalidator` double) |
| 16 | Exchange-rate refresh | Implemented + tested | `ExchangeRate` (decimal-string, positive, ≤8dp canonical, `^[A-Z]{3}$`, source ≤40); `HttpExchangeRateFetcher` (Myfin→NBRB for BYN, Walutomat→NBP for PLN, 4s timeout, decimal tokens only, no floats); `ExchangeRateService` (EUR synthetic 1.00000000, 6h freshness, cache→DB→fetch→stale-DB chain, stale never cached); `ExchangeRateRefresher` + `commerce:refresh-exchange-rates`; `tests/Unit/Commerce/{ExchangeRateTest,HttpExchangeRateFetcherTest,ExchangeRateServiceTest,ExchangeRateRefresherTest,RefreshExchangeRatesHandlerTest}.php`, `tests/Unit/Command/CommerceCommandsTest.php` |
| 17 | Cache warming | Implemented + tested | `StorefrontWarmer` (categories + featured + unfiltered newest pages 1..N≤20, locale whitelist en/ru/pl); `storefront:cache-warm` / `storefront:cache-clear` commands; `tests/Unit/Catalog/StorefrontWarmerTest.php`, `tests/Unit/Scheduling/StoreScheduleTest.php`; live `storefront:cache-warm --pages=1 --locale=en` + `storefront:cache-clear` verified against dev Redis (generation-scoped keys observed, rotation proven) |
| 18 | Payment reconciliation | **Deferred — explicit user decision 2026-10-13** | Belongs to the Shop Pay lifecycle (row 13); deferred with it. No schedule/command built |
| 19 | Health/version | Implemented + tested | `tests/Functional/HealthEndpointTest.php`, `tests/Unit/ReadinessCheckerTest.php` |

## Scheduler / Messenger evidence

`debug:scheduler` (live stack, 2026-10-13):

| Trigger | Provider |
|---|---|
| `0 * * * *` | `RefreshExchangeRates` |
| `*/5 * * * *` | `WarmStorefrontCache` |

No Shop Pay or reconciliation entries exist. The schedule is stateful in
`cache.app` with `processOnlyLastMissedRun(true)`.

`debug:messenger`: `RefreshCatalogCache`, `WarmStorefrontCache` and
`RefreshExchangeRates` are all routed to the `async` transport with
`#[AsMessageHandler]` handlers; all handlers are redelivery-idempotent
(cache replace / rate upsert). The worker consumes `scheduler_default` +
`async` (see `compose.yaml`) and reports healthy.

## Verification evidence (2026-10-13, final pass)

| Gate | Result |
|---|---|
| `bash scripts/backend-test.sh` (disposable PostgreSQL) | **312 tests, 1,713 assertions — pass** |
| `composer test` (host, infra-gated skips) | 312 tests, 846 assertions, 42 skipped — pass |
| `composer lint` (composer.json + phpstan + yaml + container + mapping) | all green, 0 errors |
| `docker compose config --quiet` | OK |
| `doctrine:schema:validate` (live dev DB) | mapping OK, schema in sync |
| `debug:scheduler` / `debug:messenger` | entries above |
| `OpenApiContractTest` | 1 test, 99 assertions — routes ↔ OpenAPI in sync |
| Frontend `npm test` / `lint` / `typecheck` / `format:check` / `build` | 61/61 tests (10 files); all gates clean; 404 KB bundle |
| `./scripts/e2e.sh` (Playwright, isolated disposable DB + one-off test backend + host Vite) | **4/4 scenarios pass**: guest checkout→order detail; register→checkout→owned order; admin login→pending order→paid transition; mobile 390×844 no-overflow |
| Migration lifecycle (disposable DB) | latest → `Version20261013000000` (4 down, 23 queries) → latest; `doctrine:schema:validate` in sync after |
| Fixture idempotency (disposable DB) | two purge/load runs produce identical counts (products 9, variants 9, orders 7, users 3, exchange_rates 3) and identical natural keys + business timestamps |

No real external rate calls were made anywhere in tests or verification —
all provider tests use `MockHttpClient`, and the E2E test backend uses the
same fake fetcher (`services_test.yaml`).

## Browser E2E coverage

`scripts/e2e.sh` creates a uniquely suffixed disposable test database inside
the running postgres container (`APP_ENV=test` + `TEST_TOKEN`), migrates and
loads fixtures, starts a one-off test backend on `127.0.0.1:18080` and a Vite
dev server on `127.0.0.1:15173` proxying `/api` to it, runs
`frontend/e2e/storefront.spec.ts` via `@playwright/test` (local Chromium
executable, serial workers, trace/screenshot on failure only), then drops
only its own database, container, volume and processes in a trap.

Scenarios (accessible-role selectors, no test hooks): guest
browse→cart→checkout→owned `/orders/SP-*`; registered buyer
register→checkout→owned order; admin (`admin@example.test`) login→filter
pending order→legal `paid` transition; mobile menu + horizontal-overflow
assertion.

One real product bug was found and fixed via E2E: the session-fixation
strategy clears session CSRF tokens *after* the login success handler runs,
so the token advertised in the login response was invalidated and every
first mutating request after login returned 419. Fixed by
`LoginCsrfRefreshSubscriber` (firewall-dispatcher listener running after
`SessionStrategyListener`) plus regression test
`IdentityFlowTest::testLoginCsrfTokenStaysValidAfterSessionFixationClear`.

## Known gaps

- **Payment slice** (rows 13/14/18) deferred per the scope amendment above.
- **Multi-replica lock store**: `LOCK_DSN` defaults to `flock` (single host);
  production must set `LOCK_DSN=redis://redis:6379` (documented in `.env.example`).
- `testConcurrentCheckoutsCannotOversell` is timing-sensitive by nature
  (fork + second HTTP client) like all PHP concurrency harnesses.
