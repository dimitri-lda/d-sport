# Legacy-to-Symfony implementation blueprint

This document turns the two legacy codebases under `blog-api/` into a phased, evidence-based
implementation plan for the greenfield Symfony 8.1 + React 19 application that lives in the
repository root. It is the agreed contract for the work: every later pull request in this
program should be traceable to a section here.

Tags used throughout: **[fact]** is verified against checked-in source; **[decision]** is a
deliberate design choice made for this program; **[unknown]** cannot be proven from source and
must be resolved before the affected phase ships.

## 1. Purpose and decisions

### 1.1 Scope

- Implement the full Sport E storefront as a greenfield application in the repository root:
  `src/`, `config/`, `migrations/`, `tests/`, `frontend/`, `public/`, `docs/`. **[fact]** the
  root currently contains only the technical foundation described in
  [ARCHITECTURE.md](ARCHITECTURE.md).
- `blog-api/` (Laravel 13.8 + Inertia storefront) and `blog-api/symfony-api/` (the previous
  Symfony/React migration attempt) are **read-only evidence**. They receive no changes and are
  deleted in a separate, explicitly approved operation after the parity audit (Section 12).
- "Parity" means **behavioral parity**: the new system must reproduce what the Laravel
  storefront does for buyers, admins, and integrations. It does not mean schema, file, or
  framework parity.

### 1.2 Settled decisions

1. **Greenfield, not a port.** The root application is the only implementation target. No
   Laravel or previous-attempt file is copied; behavior is re-derived from evidence.
2. **Full Laravel parity, including the gaps of the previous attempt.** Email verification and
   password-confirmation flows — intentionally omitted by `blog-api/symfony-api/` — are in
   scope this time (Sections 3, 6, 7, 8).
3. **Fresh normalized PostgreSQL schema.** New Doctrine migrations only, placed after the
   existing [migrations/Version20261013000000.php](../migrations/Version20261013000000.php).
   Explicitly **rejected** [decision]: baseline migrations against an existing database,
   copying Laravel migration history, a compatibility migration, ETL/data import, a traffic
   switch, a dual-run period, a production rollback window, a Laravel AES decryptor, and
   payment-field re-encryption. The legacy application was never launched, so there is no
   production data or traffic to preserve.
4. **Synthetic data only.** Development fixtures and test builders produce deterministic,
   legacy-shaped synthetic data. The checked-in SQLite file and `DatabaseSeeder` are shape
   references, not data sources (Section 9).
5. **Foundation stays.** Symfony 8.1, PHP 8.5, React 19 + TypeScript, React Router 8, Vite 8,
   Tailwind 4, PostgreSQL 18, Redis as disposable cache, Doctrine-backed Messenger, same-origin
   SPA/API behind FrankenPHP/Caddy — all confirmed in
   [composer.json](../composer.json), [frontend/package.json](../frontend/package.json), and
   [ARCHITECTURE.md](ARCHITECTURE.md).
6. **Hybrid persistence.** Doctrine ORM entities own transactional writes and lifecycle-heavy
   aggregates (identity, cart, checkout, orders, payments, admin transitions). Explicit DBAL
   read models serve complex catalog/admin reads (recursive categories, filtered/sorted
   paginated listings, order search) where hand-written SQL is clearer and faster.
7. **Sessions in PostgreSQL.** Symfony sessions are stored in a durable `app_sessions` table
   via `PdoSessionHandler`, so Redis remains a purely disposable cache as the foundation
   architecture promises. The previous attempt's Redis session handler is not repeated.
8. **Normalized catalog.** Locale-keyed translation tables replace the legacy JSON
   `*_translations` columns; the transitional `products.brand` string is gone; product cover
   images are derived from ordered `product_images` rows instead of a second stored URL
   (Section 5).
9. **Money and rates.** Monetary values stay integer minor units (`*_cents`); exchange rates
   stay `NUMERIC(18,8)` decimal strings; VAT stays basis points. Floats are never used for
   either.
10. **Business API under `/api/v1`.** The existing health/version endpoints
    (`/api/health/live`, `/api/health/ready`, `/api/version`) are preserved unchanged.
11. **Same-origin admin.** The Laravel app served admin from an `admin.` subdomain
    ([config/admin.php](../blog-api/config/admin.php)); the SPA serves it at `/admin/*` on the
    same origin behind `ROLE_ADMIN`, which keeps a single cookie/session domain and removes a
    DNS/vhost dependency. **[decision]**
12. **Deletion is a last, separate step.** `blog-api/` is removed only after every row of the
    parity matrix is accepted and the checklist in Section 12 is complete.

## 2. Evidence and current-state inventory

### 2.1 Laravel application (`blog-api/`)

The Laravel application defines the source business behavior.

| Area | Evidence | What it establishes |
| --- | --- | --- |
| HTTP surface | [blog-api/routes/web.php](../blog-api/routes/web.php), [blog-api/routes/auth.php](../blog-api/routes/auth.php) | 38 registered HTTP routes plus one admin redirect: storefront, cart, checkout, order, Shop Pay, webhook, preferences, profile, admin, and the full auth/verification family |
| Auth behavior | `blog-api/app/Http/Controllers/Auth/` (9 controllers) | Registration, login, logout, forgot/reset password, email verification prompt/verify/resend, confirmable password, password update |
| Storefront | [StoreController](../blog-api/app/Http/Controllers/StoreController.php), [StorefrontCatalog](../blog-api/app/Services/Catalog/StorefrontCatalog.php) | Home (top categories + up to 8 featured products; the seeder marks 4 featured), catalog with `q`/`category`/`sort` + page size 12, product detail with breadcrumbs/related, checkout, order view restricted to owner or the guest session that placed it (404 otherwise) |
| Cart | [CartService](../blog-api/app/Services/CartService.php), [CartController](../blog-api/app/Http/Controllers/CartController.php) | Application code intends one cart per user via `firstOrCreate`, but the legacy database does not enforce uniqueness; guest carts use a session `cart_token` UUID; cart payload embeds the per-market quote |
| Ordering | [PlaceOrder](../blog-api/app/Application/Orders/PlaceOrder.php), [OrderStatus](../blog-api/app/Domain/Orders/ValueObjects/OrderStatus.php), [DeliveryMethod](../blog-api/app/Domain/Orders/ValueObjects/DeliveryMethod.php) | Transactional checkout: `lockForUpdate` on variants, price revalidation, stock decrement, `SP-` + 8-char order number, cart clear; status machine `pending_payment → paid → processing → shipped → delivered` with `cancelled`/`refunded` branches; standard/express delivery |
| Pricing/markets | [MarketContext](../blog-api/app/Services/MarketContext.php), [PricingService](../blog-api/app/Services/PricingService.php), [config/commerce.php](../blog-api/config/commerce.php) | Session-stored market, geo-IP fallback (`ipwho.is` URL template, 30-day hit / 600 s miss cache), default `DE`; markets BY (BYN/ru/20 %) and PL (PLN/pl/23 %) plus a 27-country EU VAT table and `eu_global` fallback; locales `en`/`ru`/`pl`; net-EUR × rate → gross with VAT; free standard shipping at a converted 50 € gross threshold |
| Exchange rates | [ExchangeRateService](../blog-api/app/Services/ExchangeRateService.php) | `exchange_rates` table, 6 h freshness, per-currency source chains (BYN: myfin scrape → NBRB API; PLN: Walutomat → NBP API), separate TTL-cached value backed by the table |
| Catalog cache | [StorefrontCache](../blog-api/app/Services/Cache/StorefrontCache.php), [StorefrontCacheObserver](../blog-api/app/Observers/StorefrontCacheObserver.php) | Generation-scoped groups `tree`/`list`/`card`, stale-while-revalidate, batch card reads, post-commit invalidation on model writes, debounced warm trigger |
| Shop Pay | [ShopPayService](../blog-api/app/Application/Payments/ShopPayService.php), `blog-api/app/Services/ShopPay/` (4 classes) | Session create/reuse, quote update with same-country constraint, submit with idempotency key + 30 s submission lease + HMAC payment-method hash, reconcile by `shop-pay:{order.number}` source identifier with amount/currency verification |
| Shopify webhook | [ShopifyWebhookController](../blog-api/app/Http/Controllers/ShopifyWebhookController.php) | `X-Shopify-Hmac-Sha256` base64 HMAC check, `X-Shopify-Webhook-Id` UUID dedup via `shopify_webhook_deliveries`, topic capture, reconcile dispatch, row deleted on failure when newly created |
| Scheduling | [routes/console.php](../blog-api/routes/console.php), `blog-api/app/Console/Commands/` (5) | `commerce:refresh-exchange-rates` hourly; `storefront:cache-warm` every 5 min; `shop-pay:reconcile` every 5 min when Shop Pay is enabled |
| Admin | [Admin/OrderController](../blog-api/app/Http/Controllers/Admin/OrderController.php), [EnsureUserIsAdmin](../blog-api/app/Http/Middleware/EnsureUserIsAdmin.php), `blog-api/app/Application/Orders/Admin/` | Order list/detail/status transitions on `admin.` subdomain behind `auth` + `admin` middleware; roles `user`/`admin`/`superadmin` |
| Translations | [TranslationCatalog](../blog-api/app/Services/TranslationCatalog.php), [lang/store.yaml](../blog-api/lang/store.yaml), `blog-api/lang/{en,pl,ru}/` | Keyed YAML catalog of UI strings in `en`/`ru`/`pl` plus per-locale directories; translations version participates in cache keys |
| Frontend | `blog-api/resources/js/` (42 files) | Inertia + React JSX pages: Home/Catalog/Product/Cart/Checkout/Order/Dashboard/Profile + auth pages (Login, Register, ForgotPassword, ResetPassword, VerifyEmail, ConfirmPassword) + Admin orders + layouts/components + `lib/i18n.js` |
| Schema | `blog-api/database/migrations/` (11 migrations) | 19 business/auth tables (17 store/payment tables + `users` + `password_reset_tokens`) plus 6 Laravel infrastructure tables (`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`) — see Section 5.5 |
| Seed shape | [DatabaseSeeder](../blog-api/database/seeders/DatabaseSeeder.php), [database.sqlite](../blog-api/database/database.sqlite) | 4 top categories × 2 subcategories, 8 products, one variant each, specs, images; **shape reference only** — no row is imported |
| Tests | `blog-api/tests/` (16 feature + 3 unit files, plus `tests/TestCase.php`) | Coverage of auth incl. verification/confirmation, pricing, catalog, cart/checkout, Shop Pay, cache, translations, admin |

**Auth caveat [fact]:** the Laravel verification routes, controllers, Inertia pages, and feature
tests exist, but `App\Models\User` has the `MustVerifyEmail` implementation commented out, so
automatic verification mail and contract enforcement are incomplete in legacy. The target
implements the intended complete flow rather than reproducing that defect.

### 2.2 Previous Symfony attempt (`blog-api/symfony-api/`)

A behavior reference — not code to copy.

**Reusable behavior [fact]:**

- JSON API shape with 32 method-bearing `/api/v1` routes in
  `blog-api/symfony-api/src/Presentation/Http/Api/V1/` (context, preferences, categories,
  products, home, cart, checkout, orders, auth register/login/logout/forgot/reset, profile,
  admin orders, Shop Pay, Shopify webhook) plus `HealthController`.
- CSRF/session authentication: `json_login` firewall, `remember_me`, `login_throttling`
  (5/min), `ApiCsrfSubscriber`, `LogoutSubscriber` in `src/Infrastructure/Security/` and
  [security.yaml](../blog-api/symfony-api/config/packages/security.yaml).
- PostgreSQL DBAL persistence services (`DbalStorefrontCatalog`, `DbalCartService`,
  `DbalOrderReader`, `DbalAdminOrderService`, `DbalProfileService`, `DbalExchangeRateStore`,
  `DbalUserAccounts`, `DbalUserProvider`) under `src/Infrastructure/Persistence/Doctrine/`.
- Payment payload encryption at rest (`SodiumFieldCipher`, `PaymentPayloadCodec`) under
  `src/Infrastructure/Crypto/`.
- Generation-based `StorefrontCache` port, Scheduler wiring (`StoreSchedule`), console
  commands mirroring the Laravel jobs, an OpenAPI contract at
  [openapi/openapi.yaml](../blog-api/symfony-api/openapi/openapi.yaml), and docs under
  `blog-api/symfony-api/docs/`.
- Broad tests: 8 functional + 5 integration + 9 unit files under
  `blog-api/symfony-api/tests/` whose contracts are worth re-deriving.

**Known gaps and rejected machinery [fact]:**

- No email verification, no password-confirmation endpoints or pages — this program restores
  them.
- React 18.3.1, untyped `.jsx` (43 files), Tailwind 3 — replaced by the typed React 19 stack.
- Legacy denormalized schema kept: `migrations/Version20261012000000.php` baselines the
  Laravel shape and `Version20261012001000.php` is a compatibility `IF NOT EXISTS` upgrade —
  neither pattern is carried forward.
- ORM installed but effectively unused for writes (pure DBAL) — this program uses the hybrid
  ORM/DBAL split.
- Sessions stored in Redis (`RedisSessionHandler` in
  [framework.yaml](../blog-api/symfony-api/config/packages/framework.yaml)) — replaced by
  PostgreSQL `app_sessions` so Redis stays disposable.
- `LegacyLaravelDecryptor`, `LegacyPaymentReencryptor`, `ReencryptLegacyPaymentsCommand`, and
  the legacy key — unnecessary for a greenfield database; all excluded.

### 2.3 Root foundation (implementation target)

| Existing piece | Evidence | Status |
| --- | --- | --- |
| Health endpoints | [HealthController](../src/Controller/HealthController.php), [VersionController](../src/Controller/VersionController.php), `src/Health/` probes | Keep: `GET /api/health/live`, `/api/health/ready`, `/api/version` |
| Safe API error envelope | [ApiExceptionSubscriber](../src/EventSubscriber/ApiExceptionSubscriber.php) | Keep and extend: `{error: {code, message}}` for `/api/*`; add `fields` detail for 422 (Section 7.3) |
| Doctrine | [doctrine.yaml](../config/packages/doctrine.yaml) | ORM installed, `auto_mapping: false`; this program adds explicit per-module mappings |
| Messenger | [messenger.yaml](../config/packages/messenger.yaml), [Version20261013000000](../migrations/Version20261013000000.php) | Durable `async` + `failed` Doctrine transports on `messenger_messages`; business handlers get routing entries |
| Cache | [framework.yaml](../config/packages/framework.yaml) | `cache.app` on Redis; disposable by design |
| Frontend shell | [frontend/src](../frontend/src) (App, BackendStatus, [api/client.ts](../frontend/src/api/client.ts)) | React 19.3 + Router 8.4 + TanStack Query 5 + Tailwind 4.3 + Vite 8.3 + TS 6; GET-only client to extend |
| Tests/tooling | [composer.json](../composer.json) scripts, `tests/`, [phpunit.xml.dist](../phpunit.xml.dist) | `composer test`, `composer lint` (phpstan + cs-fixer + yaml/container/schema lint); vitest/eslint/prettier/tsc scripts in frontend |
| Docker | [compose.yaml](../compose.yaml), [Dockerfile](../Dockerfile), [dev](../dev) | PostgreSQL 18, Redis, web worker, Messenger worker, Vite dev server |

**Packages still required [fact — absent from root composer.json]:** PHP extension
`ext-sodium` (fresh payment encryption) plus `symfony/security-bundle`,
`symfony/security-csrf`, `symfony/mailer` (+ `symfony/mime`), `symfony/http-client`,
`symfony/lock`, `symfony/scheduler`, `symfony/rate-limiter`, `symfony/translation`,
`symfony/uid`, `symfony/twig-bundle` (mail bodies) **[decision]**, and dev-only
`doctrine/doctrine-fixtures-bundle`. Optional: `nelmio/api-doc-bundle` is *not* adopted; the
OpenAPI file is maintained by hand and checked by contract tests **[decision]**.

### 2.4 Unknowns (not provable from source)

- Real traffic, dataset volumes, catalog growth rate, and operational SLOs.
- Mail provider/credentials; Shopify/Shop Pay credentials and shop domain; Walutomat token;
  whether the geo-IP provider (`ipwho.is`) is acceptable in production.
- Production rate-limit thresholds and webhook delivery/ordering guarantees.
- Product image hosting strategy beyond placeholder URLs (CDN, object storage) — assumed
  external URLs for now **[decision]**.
- Whether an `admin.` subdomain exists in the target environment (moot under the same-origin
  admin decision).

## 3. Feature parity matrix

Every capability of the Laravel app gets exactly one row. "Prev." summarizes
`blog-api/symfony-api/`. All routes are under `/api/v1`; React routes live in the SPA.

| # | Capability | Laravel source | Prev. | Target backend (module · API) | Target React route | Persistence | Required tests | Acceptance |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Market context + preferences | MarketContext, MarketPreferenceController, SetMarketLocale | done | Commerce · `GET /context`, `PUT /preferences` | market switcher in StoreLayout | `exchange_rates` (read), session | unit + functional | market/locale resolve and persist in session; explicit unsupported preference returns 422; only missing/invalid/geo-IP-resolved context falls back to the configured default |
| 2 | Catalog browsing | StorefrontCatalog, StoreController::catalog | done | Catalog · `GET /categories`, `GET /products`, `GET /store/home` | `/`, `/shop` | catalog read models | functional + DBAL integration | tree incl. nested counts, `q`/category/sort, page size 12, only `active` products |
| 3 | Localized product detail | StoreController::product, models | done | Catalog · `GET /products/{slug}` | `/shop/:slug` | products + translations | functional | locale fallback chain, breadcrumbs, related, 404 when inactive |
| 4 | Guest/user cart | CartService, CartController | done | Cart · `GET /cart`, `POST /cart/items`, `PATCH`/`DELETE /cart/items/{id}` | `/cart` | carts, cart_items | functional | guest token cart, merge on login, per-market totals + delivery quotes |
| 5 | Checkout + order placement | PlaceOrder, PlaceOrderRequest | done | Checkout · `GET /checkout`, `POST /orders` | `/checkout` | orders, order_items, order_addresses, product_variants | integration + concurrency | row-locked stock decrement, price revalidation, `SP-` number, cart cleared |
| 6 | Order history + detail | StoreController::order, dashboard route | done | Orders · `GET /me/orders`, `GET /orders/{number}` | `/account/orders`, `/orders/:number` | orders + items + address | functional incl. 404 ownership | owner or placing guest session only; others get 404 |
| 7 | Register/login/logout | RegisteredUser-, AuthenticatedSessionController | done | Identity · `POST /auth/register`, `/auth/login`, `/auth/logout` | `/register`, `/login` | users, app_sessions | functional | session cookie auth, login throttle, remember-me, cart merge on login |
| 8 | Email verification | VerifyEmailController, EmailVerification*Controller | **missing** | Identity · `GET /auth/email/verify/{id}/{hash}`, `POST /auth/email/verification-notification` | `/verify-email` | users | functional + mail | signed expiring link verifies and redirects to `/verify-email?verified=1`; prompt/resend UI at `/verify-email`; resend throttled; verified gate enforced |
| 9 | Forgot/reset password | PasswordResetLink-, NewPasswordController | done | Identity · `POST /auth/forgot-password`, `/auth/reset-password` | `/forgot-password`, `/reset-password` | password_reset_tokens | functional + mail | generic response either way; hashed single-use expiring token |
| 10 | Password confirmation | ConfirmablePasswordController | **missing** | Identity · `GET`/`POST /auth/confirm-password` | `/confirm-password` | app_sessions | functional | status + confirm endpoints and UI exist; available for future opt-in sensitive routes |
| 11 | Profile: info/address/password/delete | ProfileController, SavedAddressController, PasswordController | done | Identity · `GET`/`PATCH /profile`, `PUT /profile/address`, `PUT /profile/password`, `DELETE /profile` | `/profile` | users, saved_addresses | functional | address upsert (one per user), password change, delete requires password |
| 12 | Admin orders | Admin/OrderController, EnsureUserIsAdmin | done | Admin · `GET /admin/orders`, `GET`/`PATCH /admin/orders/{id}[/status]` | `/admin/orders`, `/admin/orders/:id` | orders read model + transitions | functional + authz | `ROLE_ADMIN` only; legal transitions enforced; 403/404 correct |
| 13 | Shop Pay lifecycle | ShopPayService, ShopPayPaymentController | done | Payments · `POST /orders/{number}/payments/shop-pay/session`, `PATCH …/quote`, `POST …/submit`, `POST …/reconcile` | `/orders/:number` (payment block) | payment_sessions, payment_attempts, orders | integration w/ mocked HTTP | idempotent submit, 30 s lease, quote same-country rule, reconcile marks paid |
| 14 | Shopify webhook | ShopifyWebhookController | done | Payments · `POST /webhooks/shopify/orders` | — | shopify_webhook_deliveries, payment_sessions | functional + replay | HMAC verified, CSRF-exempt, dedup by delivery UUID, 200 on no-op |
| 15 | Storefront caching | StorefrontCache, observer | done | Infrastructure/Cache | — | Redis cache | unit + functional | generation invalidation, SWR, post-commit writes, Redis-down fallback |
| 16 | Exchange-rate refresh | ExchangeRateService, RefreshExchangeRates cmd | done | Commerce + Messenger/Scheduler | — | exchange_rates | unit + integration | source chain + fallback order, 6 h freshness, stale fallback kept |
| 17 | Cache warming | WarmStorefrontCache cmd, observer | done | Infrastructure/Cache + Scheduler | — | Redis cache | functional | scheduled warm rebuilds tree/list/card groups |
| 18 | Payment reconciliation | ReconcileShopPayPayments cmd | done | Payments + Scheduler | — | payment_sessions, orders | integration | pending orders reconciled, mismatched receipts flagged, idempotent |
| 19 | Health/version | — | done | foundation (unchanged) | — | probes | existing functional tests | `live`/`ready`/`version` unchanged, 503 readiness envelope preserved |

Rows 8 and 10 are new relative to the previous attempt; every other row must additionally
survive the Section 11 gates, not just exist.

> **Scope amendment (2026-10-13):** the user explicitly removed the payment-provider slice
> from scope. Rows 13 (Shop Pay), 14 (Shopify webhook) and 18 (payment reconciliation,
> part of the Shop Pay lifecycle) are **deferred**, not accepted. Their neutral schema,
> ORM entities, fixtures and `SodiumFieldCipher` are retained for a later slice.
> [IMPLEMENTATION_STATUS.md](IMPLEMENTATION_STATUS.md) is the authoritative per-row
> status record for the final audit.

## 4. Target architecture

### 4.1 Module layout

```
src/
  Controller/                 # foundation health/version controllers (unchanged)
  EventSubscriber/            # ApiExceptionSubscriber (extended, not replaced)
  Health/                     # readiness/liveness probes (unchanged)
  Domain/
    Identity/                 # User, UserRole enum, EmailVerification, credentials policy
    Catalog/                  # Category, Product, Variant, Image, Specification, Brand, Manufacturer
    Cart/                     # Cart, CartItem
    Ordering/                 # Order aggregate, OrderLine, OrderStatus, DeliveryMethod, ShippingAddress
    Payment/                  # PaymentSession, PaymentAttempt, status enums, webhook delivery
    Commerce/                 # Market, Country, Locale, Money, VatRate, ExchangeRate value objects
  Application/
    Identity/                 # register/login/verify/reset/confirm/profile use cases + DTOs
    Catalog/                  # StorefrontCatalog port (read side)
    Cart/                     # cart commands + cart query
    Checkout/                 # PlaceOrder use case
    Orders/                   # order history/detail queries
    Admin/                    # admin order list/detail/transition use cases
    Payments/                 # ShopPay orchestration port, reconciliation
    Commerce/                 # MarketContext, PricingService, ExchangeRateService port
  Infrastructure/
    Persistence/
      Orm/                    # Doctrine entities, explicit mappings, ORM repositories (writes)
      Dbal/                   # read models: catalog tree/cards/detail, admin order queries
    Cache/                    # generation-scoped StorefrontCache on cache.app (Redis)
    Crypto/                   # SodiumFieldCipher + PaymentPayloadCodec (fresh design)
    Http/                     # geo-IP resolver, exchange-rate fetchers (HttpClient)
    Payments/                 # ShopPay/Shopify HTTP clients, config, request factory
    Security/                 # DBAL user provider, login handlers, CSRF subscriber, reset tokens
    Scheduling/               # StoreSchedule (Scheduler component)
    Messenger/                # async handlers: mail, cache warm, reconciliation
  Presentation/
    Http/Api/V1/              # controllers + request DTOs (thin; map to Application)
    Console/                  # CLI commands for ops (cache clear/warm, reconcile, rates, webhook subscribe)
```

Dependency direction: `Presentation → Application → Domain`; `Infrastructure` implements
ports defined in `Application`/`Domain`. Controllers stay thin: validate DTO → call use case →
serialize result. Foundation modules (`Controller/`, `Health/`, `EventSubscriber/`) are
retained, with `ApiExceptionSubscriber` extended as specified in Sections 6 and 7
**[decision]**.

### 4.2 Hybrid persistence rules

- **ORM (writes):** Identity, Cart, Ordering, Payment aggregates and admin transitions use
  mapped entities with repositories; unit-of-work gives transactional consistency for
  checkout, payment, and status changes.
- **DBAL (reads):** catalog tree/cards/detail and admin order list use dedicated read-model
  classes returning plain arrays — recursive category traversal, filtered pagination, and
  per-locale joins stay in explicit SQL.
- **Mapping:** `auto_mapping: false` stays; each module registers explicit mappings in
  `config/packages/doctrine.yaml`. `doctrine:schema:update` is prohibited outside versioned
  migrations. `doctrine:schema:validate --skip-sync` (already in `composer lint`) checks
  mapping validity but explicitly does not prove the migrated database is in sync — that is
  the job of the full no-`--skip-sync` integration gate in Section 11.
- **FrankenPHP worker safety:** no request, user, or transaction state in long-lived mutable
  services; per-request caches are method-scoped or resettable, per
  [ARCHITECTURE.md](ARCHITECTURE.md) worker-mode rules.

## 5. Fresh database schema and migration plan

### 5.1 Principles

- All tables are new; nothing is created `IF NOT EXISTS` against a legacy shape. Timestamps
  are `TIMESTAMPTZ` in UTC. Primary keys are `BIGINT GENERATED ALWAYS AS IDENTITY` unless
  noted **[decision]**.
- Money in `*_cents INTEGER`; rates `NUMERIC(18,8)`; VAT `SMALLINT` basis points.
- Invariants the legacy schema lacked are enforced with `CHECK`/`UNIQUE`/FK rules below.
- Production migrations are forward-only. Each migration still implements a reversible
  `down()`, exercised only on disposable test databases per the Section 11 lifecycle gate —
  never as a production rollback mechanism.

### 5.2 Tables

**`users`** — `id` PK; `email VARCHAR(320)` + functional unique index
`CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))` **[decision: index
expression instead of citext, no extension dependency]**; `password_hash VARCHAR(255)`;
`display_name VARCHAR(120)`; `role VARCHAR(20) DEFAULT 'user' CHECK (role IN
('user','admin','superadmin'))`; `email_verified_at TIMESTAMPTZ NULL`; `remember_token
VARCHAR(100) NULL UNIQUE`; `accepted_terms BOOLEAN NOT NULL DEFAULT false`;
`terms_accepted_at TIMESTAMPTZ NULL`; `CHECK (accepted_terms = (terms_accepted_at IS NOT
NULL))` (accepted terms require a timestamp); `created_at`, `updated_at`.

**`app_sessions`** — `sess_id VARCHAR(128)` PK; `sess_data BYTEA`; `sess_lifetime INTEGER`;
`sess_time INTEGER`; index on `sess_time`. Storage for `PdoSessionHandler`; replaces both
Laravel `sessions` and the previous attempt's Redis sessions **[decision]**.

**`password_reset_tokens`** — `id` PK; `user_id UNIQUE` FK→users `ON DELETE CASCADE` (one
active token per user: issuing a new token replaces the previous one); `token_hash CHAR(64)
UNIQUE` (SHA-256 of the emailed token; never stored plaintext); `expires_at TIMESTAMPTZ`;
`created_at`. Index on `expires_at`. Redesign of Laravel's `password_reset_tokens`
**[decision]**.

**`saved_addresses`** — `id` PK; `user_id UNIQUE` FK→users `CASCADE` (one address per user);
`first_name`/`last_name VARCHAR(80)`; `line1`/`line2 VARCHAR(180)` (line2 nullable); `city
VARCHAR(80)`; `postal_code VARCHAR(20)`; `country CHAR(2) CHECK (country ~ '^[A-Z]{2}$')`;
timestamps.

**`categories`** — `id` PK; `parent_id` NULL FK→categories `ON DELETE SET NULL`; `slug
VARCHAR(160) UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')`; `image_url TEXT NULL`;
`position SMALLINT DEFAULT 0 CHECK (position >= 0)` **[decision: stable sibling ordering]**;
timestamps.

**`category_translations`** — PK `(category_id, locale)`; `category_id` FK `CASCADE`; `locale
VARCHAR(5) CHECK (locale ~ '^[a-z]{2}$')`; `name VARCHAR(160)`.

**`brands`**, **`manufacturers`** — `id` PK; `name VARCHAR(160)`; `slug VARCHAR(160) UNIQUE`;
timestamps.

**`products`** — `id` PK; `category_id` FK `RESTRICT`; `brand_id` NULL FK `RESTRICT`;
`manufacturer_id` NULL FK `RESTRICT`; `slug` unique + slug CHECK; `price_cents_net INTEGER
CHECK (>= 0)`; `compare_at_price_cents_net INTEGER NULL CHECK (compare_at_price_cents_net IS
NULL OR compare_at_price_cents_net >= price_cents_net)`; `featured BOOLEAN DEFAULT false`;
`active BOOLEAN DEFAULT true`; timestamps. **No `brand` string, no `image_url`.**

**`product_translations`** — PK `(product_id, locale)`; FK `CASCADE`; `name VARCHAR(200)`;
`description TEXT`.

**`product_variants`** — `id` PK; `product_id` FK `CASCADE`; `sku VARCHAR(64) UNIQUE`;
`price_cents_net INTEGER NULL CHECK (>= 0)` (null = inherit product price); `stock INTEGER
DEFAULT 0 CHECK (>= 0)`; timestamps.

**`product_variant_translations`** — PK `(variant_id, locale)`; FK `CASCADE`; `name
VARCHAR(160)`.

**`product_images`** — `id` PK; `product_id` FK `CASCADE`; `url TEXT`; `alt VARCHAR(255)
NULL`; `position SMALLINT DEFAULT 0 CHECK (position >= 0)`; `UNIQUE (product_id, position)`;
timestamps. Cover image = lowest `position` **[decision]**.

**`product_specifications`** — `id` PK; `product_id` FK `CASCADE`; `name VARCHAR(120)`;
`value VARCHAR(255)`; `unit VARCHAR(30) NULL`; `position SMALLINT DEFAULT 0 CHECK (position
>= 0)`.

**`carts`** — `id` PK; `user_id NULL UNIQUE` FK→users `CASCADE` (one cart per user);
`guest_token UUID NULL UNIQUE`; `CHECK ((user_id IS NOT NULL) <> (guest_token IS NOT
NULL))` (a cart is either user-owned or guest-owned, never both, never neither);
timestamps.

**`cart_items`** — `id` PK; `cart_id` FK `CASCADE`; `product_variant_id` FK `RESTRICT`;
`quantity INTEGER CHECK (> 0)`; `UNIQUE (cart_id, product_variant_id)`; timestamps.

**`orders`** — `id` PK; `user_id NULL` FK `SET NULL`; `number VARCHAR(20) UNIQUE`; `email
VARCHAR(320)`; `phone VARCHAR(40)`; `status VARCHAR(20) DEFAULT 'pending_payment' CHECK
(status IN ('pending_payment','paid','processing','shipped','delivered','cancelled',
'refunded'))`; `delivery_method VARCHAR(20) CHECK (delivery_method IN ('standard',
'express'))`;
`subtotal_cents`/`delivery_cents`/`total_cents INTEGER CHECK (>= 0)`; `CHECK (total_cents =
subtotal_cents + delivery_cents)`; `currency CHAR(3) CHECK (~ '^[A-Z]{3}$')`; `market
VARCHAR(20)`; `locale VARCHAR(5)`; `base_currency CHAR(3) DEFAULT 'EUR' CHECK (base_currency ~
'^[A-Z]{3}$')`; `exchange_rate NUMERIC(18,8) NULL CHECK (> 0)`; `vat_rate_basis_points
SMALLINT NULL CHECK (BETWEEN 0 AND 10000)`;
`net_subtotal_cents`/`tax_cents`/`delivery_net_cents`/`delivery_tax_cents INTEGER NULL CHECK
(>= 0)`; `inventory_reserved_at`, `inventory_released_at TIMESTAMPTZ NULL`; `CHECK
(inventory_released_at IS NULL OR (inventory_reserved_at IS NOT NULL AND
inventory_released_at >= inventory_reserved_at))`; timestamps.

**`order_addresses`** — `id` PK; `order_id UNIQUE` FK `CASCADE` (at most one snapshot at the
DB level; checkout atomically creates the required snapshot); same field set as
`saved_addresses`.

**`order_items`** — `id` PK; `order_id` FK `CASCADE`; `product_variant_id NULL` FK `SET
NULL`; `name`, `variant_name VARCHAR(200)`; `unit_price_cents INTEGER CHECK (>= 0)`;
`net_unit_price_cents INTEGER NULL CHECK (net_unit_price_cents >= 0)`; `quantity INTEGER
CHECK (> 0)`; `line_total_cents INTEGER CHECK (>= 0)`; `tax_cents INTEGER NULL CHECK
(tax_cents >= 0)`; `CHECK (line_total_cents = unit_price_cents * quantity)`; timestamps.

**`exchange_rates`** — `currency CHAR(3)` PK **[decision]**; `rate NUMERIC(18,8) CHECK (>
0)`; `source VARCHAR(40)`; `quoted_at TIMESTAMPTZ`; `updated_at`.

**`payment_sessions`** — `id` PK; `order_id UNIQUE` FK `CASCADE` (one session per order);
`provider VARCHAR(20) DEFAULT 'shop_pay' CHECK (provider IN ('shop_pay'))`;
`source_identifier VARCHAR(120) UNIQUE`; `session_token_encrypted TEXT NULL`
(sodium-encrypted from the first write); `session_token_hash CHAR(64) NULL UNIQUE`;
`checkout_url TEXT NULL`; `receipt_token_encrypted TEXT NULL`; `status VARCHAR(20) DEFAULT
'pending' CHECK (status IN ('pending','ready','processing','paid','failed'))`;
`provider_order_id VARCHAR(120) NULL`; `last_error TEXT NULL`; `paid_at TIMESTAMPTZ NULL`;
timestamps.

**`payment_attempts`** — `id` PK; `payment_session_id` FK `CASCADE`; `payment_method_hash
CHAR(64)`; `idempotency_key UUID UNIQUE`; `payload_encrypted TEXT NOT NULL` (encrypted);
`receipt_token_encrypted TEXT NULL` (never plaintext); `amount_cents INTEGER NULL CHECK
(>= 0)`; `currency CHAR(3) NULL CHECK (currency ~ '^[A-Z]{3}$')`; `status VARCHAR(20)
DEFAULT 'pending' CHECK (status IN ('pending','processing','completed','failed'))`;
`last_error TEXT NULL`; timestamps; `UNIQUE (payment_session_id, payment_method_hash)`.

**`shopify_webhook_deliveries`** — `id` PK; `delivery_id UUID UNIQUE`; `topic VARCHAR(80)`;
`processed_at TIMESTAMPTZ NULL`; timestamps; partial index on unprocessed rows.

**`messenger_messages`** — existing foundation table; unchanged **[fact]**.

**Localization invariant:** the translation tables have no base-name columns — localized names
and descriptions live only in `*_translations` rows. Application and fixture validation
require an `en` row for every category, product, and variant; the read-side fallback is
requested locale → required `en` row, never a base column.

### 5.3 Key indexes (beyond PK/UNIQUE above)

| Table | Index | Purpose |
| --- | --- | --- |
| categories | `(parent_id)` | tree traversal |
| products | `(category_id, price_cents_net, id)` WHERE `active` | category-filtered listing + `price_asc`/`price_desc` sorts |
| products | `(price_cents_net, id)` WHERE `active` | unfiltered price sorts |
| products | `(id DESC)` WHERE `active` | default catalog list ordering/pagination |
| products | `(id DESC)` WHERE `active AND featured` | home/featured list |
| cart_items | `(cart_id)` | cart load |
| orders | `(user_id, created_at DESC)` | account history |
| orders | `(status, created_at)` | admin list filters |
| orders | `(number)` (unique) | guest/account lookup |
| payment_sessions | `(status)` WHERE `status <> 'paid'` | reconciliation sweep |
| payment_attempts | `(payment_session_id, status)` | attempt history |
| shopify_webhook_deliveries | `(delivery_id)` unique; `(processed_at)` WHERE NULL | dedup + pending scan |
| app_sessions | `(sess_time)` | GC |
| password_reset_tokens | `(expires_at)` | purge |

Text search (`q`) uses `ILIKE` on the representative dataset; dedicated search indexing
(trigram/tsvector) is deferred until measured against real query plans (Section 11.3).

### 5.4 Migration order (all after `Version20261013000000`)

| File | Contents |
| --- | --- |
| `Version20261101000000.php` | `users`, `app_sessions`, `password_reset_tokens`, `saved_addresses` |
| `Version20261102000000.php` | `categories` + translations, `brands`, `manufacturers`, `products` + translations, `product_variants` + translations, `product_images`, `product_specifications` |
| `Version20261103000000.php` | `carts`, `cart_items`, `orders`, `order_addresses`, `order_items`, `exchange_rates` |
| `Version20261104000000.php` | `payment_sessions`, `payment_attempts`, `shopify_webhook_deliveries` |

Version timestamps are placeholders — generated names must sort after `20261013000000`.
Every migration aborts on non-PostgreSQL platforms (same pattern as the existing one), has a
real `down()`, and passes the lifecycle test in Section 11: on a disposable database, migrate
to latest → migrate back to `DoctrineMigrations\Version20261013000000` → migrate to latest
again.

### 5.5 Legacy → new disposition matrix

| Legacy table/column | Disposition | Notes |
| --- | --- | --- |
| `users` (+ `role`) | Retained, normalized | unique lower-cased email; role CHECK; keep `email_verified_at`, `remember_token`, `accepted_terms` + `terms_accepted_at` with consistency CHECK |
| `password_reset_tokens` | Replaced | one active token per user (`user_id` UNIQUE), hashed tokens, expiry |
| `sessions` | Replaced | `app_sessions` via PdoSessionHandler |
| `cache`, `cache_locks` | Dropped | Redis `cache.app` replaces |
| `jobs`, `job_batches`, `failed_jobs` | Dropped | `messenger_messages` + `failed` transport replace |
| `categories` + `name_translations` (JSON) | Normalized | `categories` + `category_translations`; add `position` |
| `products` + JSON translations | Normalized | `product_translations` for name/description |
| `products.brand` (string) | **Dropped** | transitional column superseded by `brand_id` |
| `products.image_url` | **Dropped** | cover derived from ordered `product_images` |
| `brands`, `manufacturers` | Retained | unchanged shape |
| `product_variants` + JSON translations | Normalized | `product_variant_translations` |
| `product_images` (+`alt`) | Retained | gains `UNIQUE (product_id, position)`; becomes sole image source |
| `product_specifications` | Retained | unchanged |
| `carts`, `cart_items` | Retained | `token` renamed `guest_token`; exclusive owner-or-token XOR CHECK added |
| `orders`, `order_addresses`, `order_items` | Retained, hardened | status/method CHECKs, total consistency, `TIMESTAMPTZ` |
| `saved_addresses` | Retained | one-per-user enforced |
| `exchange_rates` | Retained | currency becomes PK |
| `payment_sessions`, `payment_attempts` | Retained, hardened | plaintext token columns become `*_encrypted`; provider/status CHECKs |
| `shopify_webhook_deliveries` | Retained | unchanged |

## 6. Backend implementation slices

Each slice lists its modules (Section 4.1) and non-obvious rules. Direction: Presentation →
Application → Domain; Infrastructure behind ports.

1. **API plumbing.** Extend `ApiExceptionSubscriber` with a `fields` map for 422s; shared
   `ApiProblem` mapping for `DomainException`; request DTOs validated by `symfony/validator`;
   `App\Presentation\Http\Api\V1` controllers only.
2. **Commerce.** `MarketContext` (session preference → geo-IP → default `DE`; locale ∈
   en/ru/pl; `eu_global` fallback), `CountryCatalog` + VAT table from
   `config/commerce.php` values ported to `config/packages/commerce.yaml` **[decision]**,
   `PricingService` (net×rate→gross, delivery quotes, free-shipping threshold),
   `ExchangeRateService` behind an `ExchangeRateStore` port with the same source chains and
   6 h freshness as the Laravel service.
3. **Catalog.** `StorefrontCatalog` port; DBAL read model implements categories-with-counts
   (recursive descendant aggregation), `featured` (≤8), `page(filters,page,perPage=12)` with
   `q`/`category`/`sort`, `detail` (breadcrumbs, related, specifications, gallery), and
   `cards(ids)` batch reads; locale fallback: requested locale → required `en` row (the
   Section 5.2 invariant; there are no base-name columns to fall back to).
4. **Cart.** Guest carts by `guest_token` (stored in session as `cart_token` equivalent);
   user carts `user_id`-unique; **cart merge on authentication**: on login/register, each
   guest item overwrites the same-variant quantity in the user cart — parity with the
   legacy `updateOrCreate` and the previous attempt's `ON CONFLICT … quantity =
   EXCLUDED.quantity` upsert, not a sum — then the guest cart is deleted. Stock stays
   validated by the normal cart/checkout rules.
5. **Checkout.** `PlaceOrder` in one transaction: `SELECT … FOR UPDATE` on variants,
   availability + `active` check, price revalidation against stored net price, stock
   decrement, order + address + items persisted with market/locale/rate/VAT snapshot,
   `inventory_reserved_at` set, cart cleared, `SP-` + 8-char number, session `last_order`.
   Inventory release on cancel is idempotent (`inventory_released_at` guard).
6. **Identity/security.** `security-bundle` with `json_login` (email+password), session
   cookies, `remember_me`, `login_throttling` (5/min), role hierarchy
   `SUPER_ADMIN→ADMIN→USER`; CSRF via `security-csrf` + a port of `ApiCsrfSubscriber`;
   `PdoSessionHandler` on `app_sessions`; **email verification** via signed expiring URLs
   (`signature`/`expires` params, `throttle:6,1` equivalent); **password confirmation** via
   `GET` status + `POST` confirm storing a timestamped marker in session (Laravel's
   `auth.password_confirmed_at` equivalent), available for future opt-in sensitive routes —
   password change and account deletion keep direct current-password checks; hashed reset
   tokens; profile actions including password-verified account deletion.
7. **Admin.** `ROLE_ADMIN`-guarded controllers; DBAL list (status/date filters, pagination),
   ORM transition path enforcing the `OrderStatus` state machine.
8. **Payments.** `ShopPayConfiguration` (enabled flag + public config), Storefront/Admin API
   clients over `symfony/http-client` with timeouts, `ShopPayPaymentRequestFactory`;
   `SodiumFieldCipher`-based encryption of `session_token_encrypted`, `payload_encrypted`,
   `receipt_token_encrypted` **from the first write** (no re-encryption pass ever needed),
   with sodium AAD binding each ciphertext to its table/field/row-id context; submit
   idempotency (session lock + attempt dedup + 30 s lease); reconcile verifies
   `sourceIdentifier`, currency, and amount before marking paid; webhook verifies HMAC then
   dedups by delivery UUID. Secret boundary: Shopify/Walutomat secrets only in env, never in
   logs or payloads.
9. **Cache.** `StorefrontCache` port with generation groups `tree`/`list`/`card` and
   stale-while-revalidate; exchange rates use a separate short-TTL cache entry backed by the
   `exchange_rates` table, not a generation group. Write-side invalidation is explicit:
   application write services collect the affected product/category IDs inside the
   transaction and invalidate/dispatch only after `transactional()` returns successfully —
   no generic ORM lifecycle listeners, no pre-commit invalidation, no rollback races.
   Redis-failure falls back to direct reads; debounced warm via a Messenger message
   (replacing `defer()` + `Cache::add` debounce).
10. **Async.** `symfony/scheduler` replaces cron: `commerce:refresh-exchange-rates` hourly,
    `storefront:cache-warm` every 5 min, `shop-pay:reconcile` every 5 min when enabled;
    mail delivery routed through Messenger `async` where appropriate; all handlers
    idempotent (Doctrine transport may redeliver).

**Excluded** (explicitly): Laravel AES decryptor, re-encryption command, legacy key, baseline
migration, compatibility migration — no legacy ciphertext ever exists in the new database.

## 7. API and security contract

### 7.1 Route table

`P` = public, `S` = session-authenticated, `V` = verified-email required, `A` = `ROLE_ADMIN`,
`W` = webhook (HMAC, CSRF-exempt). All mutating routes require a valid CSRF token (header
`X-CSRF-Token`) except the webhook.

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/health/live`, `/api/health/ready`, `/api/version` | P | foundation (unchanged) |
| GET | `/api/v1/context` | P | auth user or null, market/locale/currency/VAT, countries/locales, fresh CSRF token |
| PUT | `/api/v1/preferences` | P | set country/locale in session |
| GET | `/api/v1/store/home` | P | top categories + featured products |
| GET | `/api/v1/categories` | P | category tree with counts |
| GET | `/api/v1/products` | P | `q`, `category`, `sort`, `page`; page size 12 |
| GET | `/api/v1/products/{slug}` | P | localized detail; 404 when inactive |
| GET | `/api/v1/cart` | P | resolved cart incl. per-market totals |
| POST | `/api/v1/cart/items` | P | add variant+quantity |
| PATCH/DELETE | `/api/v1/cart/items/{id}` | P | update/remove item |
| GET | `/api/v1/checkout` | P | checkout summary (cart, user, saved address) |
| POST | `/api/v1/orders` | P | place order from current cart |
| GET | `/api/v1/orders/{number}` | P\* | owner or placing guest session only; else 404 |
| GET | `/api/v1/me/orders` | V | own order history (parity with Laravel's `verified` dashboard) |
| POST | `/api/v1/auth/register` | P | create account, start session, merge cart |
| POST | `/api/v1/auth/login` | P | session login (throttled 5/min) |
| POST | `/api/v1/auth/logout` | S | end session |
| POST | `/api/v1/auth/forgot-password` | P | generic 202 response; mail if known |
| POST | `/api/v1/auth/reset-password` | P | consume hashed token |
| GET | `/api/v1/auth/email/verify/{id}/{hash}` | S | signed+expiring verification link |
| POST | `/api/v1/auth/email/verification-notification` | S | resend (throttled 6/min) |
| GET | `/api/v1/auth/confirm-password` | S | password-confirmation status |
| POST | `/api/v1/auth/confirm-password` | S | mark session as password-confirmed |
| GET/PATCH | `/api/v1/profile` | S | read/update profile |
| PUT | `/api/v1/profile/address` | S | upsert single saved address |
| PUT | `/api/v1/profile/password` | S | change password (current required) |
| DELETE | `/api/v1/profile` | S | delete account (password required) |
| GET | `/api/v1/admin/orders` | A | list/filter orders |
| GET | `/api/v1/admin/orders/{id}` | A | order detail |
| PATCH | `/api/v1/admin/orders/{id}/status` | A | legal status transition |
| POST | `/api/v1/orders/{number}/payments/shop-pay/session` | P\* | create/reuse Shop Pay session |
| PATCH | `/api/v1/orders/{number}/payments/shop-pay/quote` | P\* | update shipping/quote (same country) |
| POST | `/api/v1/orders/{number}/payments/shop-pay/submit` | P\* | idempotent payment submit |
| POST | `/api/v1/orders/{number}/payments/shop-pay/reconcile` | P\* | manual reconcile trigger |
| POST | `/api/v1/webhooks/shopify/orders` | W | HMAC-verified order webhook, dedup |

`P\*` uses the same ownership rule as order view: the guest that placed the order (session
`last_order`) or the owning user; all other callers get 404 — never 403, to avoid leaking
existence **[fact: Laravel aborts 404]**.

Two deliberate contract notes:

- **State-changing GET exception:** `GET /auth/email/verify/{id}/{hash}` is the only
  state-changing GET. It is authenticated, expiring, and hash/signature checked, then it
  redirects to the SPA route `/verify-email?verified=1`. The prompt/resend UI lives at
  `/verify-email`; all other mutations use POST/PUT/PATCH/DELETE with CSRF.
- **Session rotation:** login and register rotate the session ID; the frontend must refetch
  `GET /context` (auth state + fresh CSRF token) before issuing the next mutation.

### 7.2 Security requirements

- Same-origin cookie auth only; `Secure`, `HttpOnly`, `SameSite=Lax` session cookie; session
  ID rotated on login/logout and privilege change; `remember_me` 30-day cookie.
- Login throttling 5/min per IP+username; verification resend 6/min; Shop Pay endpoints
  30/min (parity with `throttle:30,1`); `forgot-password` returns an identical generic
  response whether or not the email exists.
- Password confirmation: `GET`/`POST /auth/confirm-password` stores a timestamped marker in
  session (Laravel's `auth.password_confirmed_at` equivalent). No inspected Laravel route
  consumes `password.confirm`, so the marker gates nothing today and does not gate admin
  actions; `PUT /profile/password` and `DELETE /profile` keep direct current-password
  checks. The marker exists for future opt-in sensitive routes **[decision]**.
- Webhook: CSRF-exempt; `X-Shopify-Hmac-Sha256` verified before parsing; `X-Shopify-Webhook-Id`
  must be a UUID; unknown topics → 200 no-op.
- Secrets (Shopify client secret, Walutomat token, mail credentials, `APP_SECRET`) only via
  env; log redaction for tokens/authorization headers; API errors never leak internals —
  they use the existing safe envelope.
- Request DTO constraints mirror Laravel form requests (field lengths from Section 5,
  `delivery_method ∈ {standard,express}`, `country` in supported list, `locale ∈
  {en,ru,pl}`, quantity ≥ 1, `sort ∈ {price_asc,price_desc}`).

### 7.3 Envelopes and pagination

- Success: business responses use the previous attempt's top-level `{data: ...}` envelope;
  collections use `{data: [...], meta: {page, per_page, total}}`. The foundation
  health/version payloads remain unchanged.
- Errors: existing envelope `{error: {code, message}}` plus optional `fields` for 422:
  `{error: {code: "validation_failed", message: "...", fields: {"email": ["…"]}}}`.
- Contract source: a maintained `openapi/openapi.yaml`; a functional test asserts every
  route in Section 7.1 is documented and every documented route exists.

## 8. React 19 + TypeScript implementation

### 8.1 Structure

```
frontend/src/
  api/            # typed client + endpoint modules (auth, cart, catalog, orders,
                  #   payments, admin, preferences, health)
  app/            # router, providers, queryClient
  components/     # ui primitives (Button, Input, Modal, StatusSelect, ProductCard…)
  layouts/        # StoreLayout, GuestLayout, AdminLayout
  features/
    catalog/      # HomePage, CatalogPage, ProductPage
    cart/         # CartPage
    checkout/     # CheckoutPage
    orders/       # OrderPage, OrdersPage (account history)
    auth/         # LoginPage, RegisterPage, ForgotPasswordPage, ResetPasswordPage,
                  #   VerifyEmailPage (prompt/resend/result), ConfirmPasswordPage
    profile/      # ProfilePage (info, address, password, delete-account sections)
    admin/        # AdminOrdersPage, AdminOrderPage
    preferences/  # MarketSwitcher
    payments/     # ShopPayPanel (SDK isolation)
  lib/            # i18n (en/ru/pl dictionaries), formatMoney/formatDate, queryKeys,
                  #   guards (RequireAuth, RequireAdmin, RequireVerified)
  routes.tsx      # route table wired to features
```

### 8.2 Route map (SPA)

`/` home · `/shop` catalog · `/shop/:slug` product · `/cart` · `/checkout` ·
`/orders/:number` · `/account/orders` (verified) · `/profile` (auth; danger-zone actions
re-check current password) · `/login` `/register` `/forgot-password` `/reset-password` `/verify-email`
`/confirm-password` (guest/auth as appropriate) · `/admin/orders`
`/admin/orders/:id` (admin guard) · `*` NotFound.

### 8.3 Client and state

- Extend [api/client.ts](../frontend/src/api/client.ts): generic `request<T>` with method,
  JSON body, CSRF header (token supplied by `GET /context` and refetched after the
  login/register session rotation), `credentials: 'same-origin'`, timeout+cancellation
  (keep `AbortSignal.any` pattern), 204 handling, and typed `ApiError` carrying `code`,
  `status`, `retryable`, `fields`; user-facing messages stay generic/localized.
- TanStack Query keys per domain (`['catalog','page',filters]`, `['cart']`, `['order',n]`,
  `['admin','orders',filters]`); mutations invalidate precisely (`cart`, `order`,
  `admin.orders`, `context`).
- Auth bootstrap: `GET /context` populates `MarketContext` + `AuthContext` (user or null);
  guards wait for bootstrap before redirecting.
- UI text lives in typed `en`/`ru`/`pl` dictionaries under `lib/i18n` (keys derived
  conceptually from [store.yaml](../blog-api/lang/store.yaml)); the API returns stable
  error/message codes that the dictionaries translate. Catalog *content* translations come
  from the database, never the dictionaries. `Intl.NumberFormat` on `locale`+`currency`
  formats VAT-included gross prices.
- Forms: accessible labels/errors tied to `fields` map; Shop Pay SDK isolated behind a
  `ShopPayAdapter` that lazy-loads only on the order page; loading/empty/error states per
  screen; responsive behavior per Tailwind 4; visual intent ported from the legacy Inertia
  pages without copying untyped code.
- Vite `/api` proxy and Caddy SPA fallback boundaries stay as documented in
  [ARCHITECTURE.md](ARCHITECTURE.md).

## 9. Representative fixtures and test data

- `doctrine/doctrine-fixtures-bundle` as **require-dev only**; fixtures are forbidden in
  prod (`APP_ENV=prod` guard + absence from prod autoload) **[decision]**.
- `App\DataFixtures\AppFixtures` — deterministic and reproducible via **purge-and-reload**:
  each run starts from an empty fixture set and produces identical rows (fixed timestamps,
  values, slugs, SKUs, emails, order numbers); zero real PII/tokens.
- Content mirrors the legacy seed's sports-store shape
  ([DatabaseSeeder](../blog-api/database/seeders/DatabaseSeeder.php)) with **fictional
  identities** and repository-controlled local placeholder assets
  (`frontend/public/fixtures/images/…`) instead of mutable Unsplash URLs:
  - users: `customer@example.test` (verified), `unverified@example.test`,
    `admin@example.test` (admin) — all with a documented dev password;
  - markets/rates: EUR base + BYN/PLN `exchange_rates` rows with fixed rates and sources;
  - catalog: 4 top categories (running, fitness, racket sports, outdoor) × 2 subcategories;
    every category/product/variant has the required `en` translation row (Section 5.2
    invariant) plus `ru`/`pl` coverage where applicable;
  - ≥ 8 products covering: featured, inactive (hidden), out-of-stock variant, variant price
    override, missing `pl` translation (fallback path), multi-image gallery, specifications,
    compare-at price;
  - carts: one guest-token cart and one user cart with overlapping variants (merge testing);
  - one saved address; orders spanning `pending_payment`, `paid`, `processing`, `shipped`,
    `delivered`, `cancelled`, `refunded` with address + items snapshots;
  - payment/webhook rows: structurally representative `payment_sessions`/`payment_attempts`/
    `shopify_webhook_deliveries` whose encrypted fields are written through
    `SodiumFieldCipher` under a deterministic development/test-only key — no raw tokens and no real
    personal data.
- Test isolation: focused builders under `tests/Support/Builders/` (`UserBuilder`,
  `CatalogBuilder`, `OrderBuilder`, `PaymentBuilder`) that construct minimal valid graphs per
  test — tests never depend on the dev fixtures.

## 10. Phased delivery plan

Each phase ends mergeable: green gates (Section 11) + acceptance below. Target files use the
Section 4/8 layout.

| Phase | Scope | Main target files | Acceptance |
| --- | --- | --- | --- |
| 0 — Foundations | deps, ORM mapping skeleton, `app_sessions` + PdoSessionHandler, CSRF subscriber, error-envelope `fields`, OpenAPI skeleton, commerce config | `composer.json`, `config/packages/{security,framework,doctrine,commerce}.yaml`, `src/EventSubscriber/`, `openapi/openapi.yaml` | `composer lint` + `composer test` green; session writes to PostgreSQL; envelope test for a forced 422 |
| 1 — Schema + data | migrations 5.4, ORM entities/repositories, DBAL stubs, fixtures, schema tests | `migrations/Version2026110*.php`, `src/Domain/**`, `src/Infrastructure/Persistence/**`, `src/DataFixtures/`, `tests/` | migration lifecycle (latest → `Version20261013000000` → latest) passes on a disposable DB; `schema:validate` clean; two clean purge-and-load runs yield the same known counts and natural keys |
| 2 — Commerce + catalog | market/pricing/rates, catalog read models + API, catalog UI | `src/{Domain,Application,Infrastructure}/{Commerce,Catalog}`, `Presentation/Http/Api/V1/{Context,Preference,Catalog}Controller.php`, `features/{catalog,preferences}` | parity rows 1–3 accepted; locale fallback covered by functional tests |
| 3 — Identity + account | security config, registration/login/logout, **email verification**, forgot/reset, **password confirmation**, profile | `config/packages/security.yaml`, `src/**/{Identity,Security}`, `features/{auth,profile}` | parity rows 7–11 accepted; verification + confirmation flows proven end-to-end (they were missing before) |
| 4 — Cart + checkout | carts incl. guest merge, transactional checkout, orders, concurrency | `src/**/{Cart,Checkout,Ordering}`, `features/{cart,checkout,orders}` | parity rows 4–6; concurrent-checkout test proves no oversell; ownership 404 proven |
| 5 — Admin | admin order list/detail/transitions + UI | `src/**/Admin`, `features/admin` | parity row 12; `ROLE_ADMIN` enforcement + legal transitions proven |
| 6 — Payments | sodium field cipher, Shop Pay clients, submit/quote/reconcile, webhook | `src/Infrastructure/{Crypto,Payments}`, `src/Application/Payments`, `features/payments` | parity rows 13–14; encryption round-trip, webhook replay/dedup, mocked-HTTP integration green |
| 7 — Cache + async | StorefrontCache, post-commit invalidation, Scheduler entries, Messenger handlers, observability | `src/Infrastructure/{Cache,Scheduling,Messenger}`, `config/packages/messenger.yaml` | parity rows 15–18; Redis-down fallback test; handler idempotency proven |
| 8 — Parity audit + cleanup | full matrix audit, docs update, OpenAPI finalize | `docs/**`, `openapi/openapi.yaml` | every matrix row marked accepted; deletion checklist (Section 12) ready for approval — `blog-api/` removal is a *separate* approved op |

## 11. Testing and acceptance gates

### 11.1 Backend (`composer test`, `composer lint`)

- **Unit:** domain (order state machine, pricing math, money/rate VOs), cache generations,
  request factory, cipher codec, translation fallback.
- **Functional (HTTP):** every Section 7.1 route — success, validation 422 envelope, authz
  (401/403/**404-ownership**), CSRF rejection without token, throttle behavior, session
  rotation on login, verification-link signature/expiry, webhook HMAC/dedup/replay.
- **Integration (PostgreSQL/Redis/Messenger):** DBAL read models, exchange-rate store,
  encrypted-field round trips, ShopPay service against mocked HTTP client, mail via
  `symfony/mailer` test transport, scheduled-handler idempotency.
- **Migration lifecycle:** on a disposable database, `doctrine:migrations:migrate` to
  latest → back to exactly `DoctrineMigrations\Version20261013000000` → to latest again;
  `doctrine:schema:validate` (full, without `--skip-sync`) must report entities matching
  the migrated schema.
- **Concurrency:** parallel checkout on one variant proves stock never goes negative and one
  order wins; parallel identical submits prove one payment attempt.

### 11.2 Frontend (`npm run test`, `lint`, `typecheck`, `format:check`, `build`)

- API client: timeout/cancel/204/error-fields mapping; CSRF header attached.
- Contexts/guards: bootstrap race, auth/admin/verified/confirmed redirects.
- Flows: catalog filter/pagination, cart add/update/merge, checkout happy path + domain error
  display, order 404/ownership, auth family incl. verify + confirm-password screens, admin
  transitions, Shop Pay adapter mocked.
- States: loading/empty/error per screen; a11y checks on forms and dialogs; production build
  must pass typecheck + vite build.

### 11.3 Contract / E2E

- OpenAPI-vs-routes synchronization test (Section 7.3).
- Browser-level happy paths (guest buy, registered buy, admin transition) against the Docker
  stack once phases 4–6 land; fixture-backed smoke data only.
- `tests/performance/health-smoke.js` stays the baseline smoke; catalog/checkout load numbers
  are measured on the representative fixture dataset with `EXPLAIN` + query counts — **no**
  million-product or 1000-RPS claims are made from fixtures **[decision]**.

### 11.4 Commands

```bash
composer test                 # phpunit: unit + functional + integration
composer lint                 # composer validate + phpstan + cs-fixer + yaml/container/schema lint
php bin/console doctrine:migrations:migrate --env=test   # targeted migration gate
cd frontend && npm run test && npm run lint && npm run typecheck && npm run format:check && npm run build
```

Docker-environment equivalents use `docker compose exec <service>` per
[compose.yaml](../compose.yaml) service names.

## 12. Risks, unknowns, and legacy deletion

### 12.1 Ranked risks and mitigations

| Rank | Risk | Mitigation |
| --- | --- | --- |
| 1 | Security regressions (session fixation, CSRF gaps, ownership leaks) | reuse proven `json_login`+CSRF pattern; functional tests for rotation, 404-ownership, every mutating route's CSRF; review gate before phase 3 merge |
| 2 | Inventory race on checkout/variant decrement | `SELECT … FOR UPDATE` + `CHECK (stock >= 0)` + concurrency test; release path idempotent |
| 3 | Payment idempotency/encryption errors (double charge, unreadable payload) | unique `(session, method_hash)` + idempotency key + 30 s lease; sodium round-trip tests; receipt amount/currency verification before `paid` |
| 4 | Schema/index gaps hurting catalog/admin reads | measured `EXPLAIN` on fixture dataset; bounded pagination; partial indexes from 5.3 reviewed in phase 7 |
| 5 | Session/CSRF mismatch between SPA and API | same-origin only, contract test for login→mutate→logout flow; webhook exemption isolated |
| 6 | Cache invalidation bugs (stale catalog/prices) | generation namespaces + explicit post-transaction invalidation dispatched by write services; Redis-outage fallback test |
| 7 | External API fragility (Shop Pay, rates, geo-IP) | HTTP timeouts, source-chain fallbacks, stale-rate fallback, webhook dedup, mocked-HTTP integration tests |
| 8 | Frontend/backend contract drift | OpenAPI sync test; typed client generated-by-hand modules reviewed per phase; shared DTO field names |

### 12.2 Facts vs decisions vs unknowns

- **Confirmed facts** are the Laravel behaviors, previous-attempt capabilities/gaps, and
  foundation pieces cited with paths in Section 2 (route/table counts, status machines, cache
  design, pricing rules).
- **Design decisions** are flagged `[decision]` inline (session storage, same-origin admin,
  normalized translations, single saved address, functional email index, exchange-rate PK,
  Twig for mail, hand-maintained OpenAPI, merge-by-variant cart).
- **Unknowns** are listed in Section 2.4; each must be resolved (or explicitly waived) before
  the phase depending on it merges — e.g., Shopify credentials before Phase 6 acceptance, mail
  provider before Phase 3 ships real mail.

### 12.3 Legacy deletion checklist (separate approved operation)

Required evidence before `blog-api/` may be deleted:

1. Every parity-matrix row in Section 3 marked accepted with its test evidence.
2. All Section 11 gates green on a clean checkout.
3. Fresh install reproducible: `composer install` → `migrate` → `fixtures:load` → storefront
   browsable, from docs alone.
4. `openapi/openapi.yaml` and `docs/` current, and two `blog-api` gates pass:
   a. no runtime import/path dependency on `blog-api` in `src`, `config`, `frontend`,
      `public`, or Composer/package configuration (checked by
      `grep -R "blog-api" src config frontend public composer.json frontend/package.json`);
   b. this document's local legacy evidence links are converted to stable SCM permalinks at
      the audited legacy commit — or the document is explicitly archived with an immutable
      source snapshot — so deleting the directory leaves no broken evidence links.
5. Secrets/configuration inventory documented (env vars, Shopify, mail, geo-IP, Walutomat).
6. Explicit user/lead approval for the deletion itself — **this blueprint performs no
   deletion and no modification of `blog-api/`**.
