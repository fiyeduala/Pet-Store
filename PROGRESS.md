# Pet Store — build progress

## Stack (verified by resolver before committing to it)

| Component | Version | Why |
|---|---|---|
| PHP | `^8.2` (dev container runs 8.4.19) | Laravel 13 needs PHP `^8.3`; the cPanel launch target may not have it. |
| Laravel | `^12.0` (12.69.2) | Runs on PHP 8.2. Upgrade path to 13 in `docs/integration-notes.md`. |
| Filament | `^4.0` (4.13.4) | Admin panel only. |
| Livewire | `^3.6` (3.8.9) | Storefront interactivity. |
| Tailwind CSS | `^4` | Built at deploy time; no Node at runtime. |
| Database | MySQL/MariaDB; SQLite in-memory for tests | |

## Phases

- [x] 0. Scaffold and configuration
- [x] 1. Domain migrations and models
- [x] 2. Money handling and pricing engine
- [x] 3. Supplier adapters (CJ live + demo)
- [x] 4. Payment gateway adapters (PayPal, Paystack, demo)
- [x] 5. Shipping and tax services
- [x] 6. Storefront
- [x] 7. Filament administration
- [x] 8. Order lifecycle, jobs, exception queue
- [x] 9. Notifications and email templates
- [x] 10. Seeders and demo data
- [x] 11. Tests
- [x] 12. Deployment and handover documentation

## What is verified

Run locally against a clean database:

- `php artisan migrate:fresh` — all 16 migrations apply
- `php artisan db:seed --class=DemoSeeder` — seeds through the real import path
- `php artisan test` — 149 tests, 386 assertions, all passing
- `npm run build` — Vite production build succeeds
- `php artisan cj:verify` — passes against the demo adapter
- Storefront pages render with real content (home, catalogue, product,
  cart, checkout, tracking, policies, auth, errors)
- Every admin page renders for an owner and is refused for a role without
  the permission

## What is NOT verified

- **Every CJdropshipping endpoint path.** `developers.cjdropshipping.com`
  was unreachable from the build environment (egress-blocked), so paths
  come from published conventions, not the official reference. They are
  configurable in `config/petstore.php`. See `docs/integration-notes.md`.
- **PayPal merchant eligibility.** Code is complete; whether the owner's
  account may accept commercial USD Checkout payments is unknown and only
  PayPal can answer it.
- **Paystack currency support.** Ships with no supported currencies, so
  it cannot be offered until someone records what Paystack confirmed.
- **Live order creation, supplier payment and tracking.** These cannot be
  verified without spending money. One deliberate live test order is
  required; the procedure is in `docs/integration-notes.md`.
- **CJ branded packaging and webhooks.** Not claimed as supported.
  Polling is used for tracking.

## Defects found and fixed during the build

Recorded because each was a genuine bug, not a hypothetical:

1. **Double-rounding in `PriceRounder`.** A rounding increment and a charm
   ending were both applied, pushing a $8.16 computed price to $9.99
   instead of $8.99 — roughly a dollar above what the rule asked for.
2. **Demo failure scenarios unreachable.** Matched with `str_ends_with`
   on the fulfilment reference, but fulfilment appends `-P1`, so the
   timeout, out-of-stock and insufficient-balance paths never fired.
3. **Supplier payment retry threw.** A retry of an already-settled payment
   raised an error instead of returning the existing record, making a safe
   retry after an ambiguous failure look like a new failure.
4. **The test suite ran against the development environment.** This
   container's shell exports `APP_ENV=local`, Laravel reads `env()` from
   `$_SERVER` before `$_ENV`, and PHPUnit's `<env>` only populates `$_ENV`.
   `tests/bootstrap.php` now makes the declared test environment
   authoritative before the framework boots.
5. **Three N+1 queries**, each surfaced by `Model::shouldBeStrict()`:
   product listings, warehouse suppliers during quoting, and the category
   list.
6. **Filament API mismatches**: the `Tab` class namespace, closure
   parameters that must be named `$state`, an unhydrated `deleted_at` read
   under strict mode, and a missing explicit dashboard page.

## Remaining owner inputs

Twelve items, listed with what each blocks, in
`docs/live-launch-checklist.md`.

## Suggested next work

Not required by the brief, but the obvious next steps:

- Address book CRUD on the storefront (addresses are read-only today).
- Customer-initiated return requests from the order page (the domain
  service and the admin side exist; there is no storefront UI).
- Broader audit coverage. Staff creation, role changes and two-factor
  resets are audited; extend the same pattern to gateway settings and
  pricing rule changes.
- Currency conversion, if Paystack NGN settlement is ever needed. The
  schema supports it; the policy and UI are deliberately not built.
