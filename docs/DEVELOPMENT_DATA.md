# Development data

Deterministic synthetic fixtures loaded with `doctrine:fixtures:load`. All identities are
fictional; no real personal data, tokens or secrets are present. Purge-and-load produces the
same row set, natural keys, counts and business timestamps on every run — `created_at` and
`updated_at` are normalized to `2026-01-15T12:00:00+00:00` after flush. Deterministic does
not cover ciphertext bytes: encrypted fixture fields keep random sodium nonces by design.

## Users

| Email | Password | Role | Email verified |
| --- | --- | --- | --- |
| `customer@example.test` | `SportE!2026` | user | yes |
| `unverified@example.test` | `SportE!2026` | user | no |
| `admin@example.test` | `SportE!2026` | admin | yes |

## Coverage

- Catalog mirrors the legacy seed shape: 4 top categories × 2 children, 9 products
  including featured, inactive (`legacy-runner-cap`), out-of-stock (`court-backpack`),
  variant price override (`move-training-mat`), a missing `pl` translation
  (`alpine-daypack-24l`), a multi-image gallery (`cloudswift-4`), specifications and a
  compare-at price (`aero-run-shorts`). Every category/product/variant has the required
  `en` translation row.
- Product/category imagery points at repository-controlled placeholders under
  `frontend/public/fixtures/images/`.
- `exchange_rates` rows for EUR (base), BYN and PLN; `shopify_webhook_deliveries` has one
  processed and one pending row. Both tables are DBAL-owned (no ORM entities).
- One saved address for the customer, one guest-token cart and one user cart with an
  overlapping variant for merge testing.
- Seven orders (`SP-00000001`–`SP-00000007`) spanning `pending_payment`, `paid`,
  `processing`, `shipped`, `delivered`, `cancelled` (with reserved+released inventory) and
  `refunded`, each with an address and item snapshot.
- Payment session/attempt rows whose `*_encrypted` fields are written through
  `SodiumFieldCipher`, so fixtures prove the real encryption path end to end.

## Field cipher key

`APP_FIELD_CIPHER_KEY` is a base64-encoded 32-byte key consumed by
`App\Infrastructure\Crypto\SodiumFieldCipher`. The value shipped in `.env.example` is a
development/test-only placeholder — generate a real key per environment with
`php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`. Encrypted fixture fields are
bound to `table.column.identifier` AAD contexts and never stored in plaintext.
