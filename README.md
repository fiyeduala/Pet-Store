# Pet Store

A US-first ecommerce storefront for dog and cat supplies, with a Filament
administration panel and a CJdropshipping supplier integration.

> **"Pet Store" is a placeholder.** The customer-facing name, tagline, logos
> and accent colour are all settings, editable in Admin → Brand. Nothing in
> the code hard-codes them.

---

## What this is

A modular monolith: one Laravel application with clear domain boundaries.
No microservices, no message broker, no Redis requirement.

```
app/
  Domain/
    Catalogue/    importing, the per-field sync policy, browsing filters
    Checkout/     cart, totals, order placement
    Fulfilment/   supplier submission, preflight checks, supplier payment
    Orders/       the five state machines
    Payments/     gateway adapters and the webhook pipeline
    Pricing/      rules, precedence, rounding, contribution
    Returns/      refunds, cancellation races, returns
    Settings/     owner-editable settings and branding
    Shipping/     warehouse selection, quoting, delivery wording
    Shared/       demo/live mode isolation
    Supplier/     the adapter contract, CJ adapter, demo adapter
    Tax/          pluggable tax with a manual-rules default
  Filament/       the admin panel (admin only; never the storefront)
  Livewire/       storefront interactivity
  Models/         Eloquent models
  Support/Money/  the exact-money value object
```

Adding a second supplier later means writing one more implementation of
`App\Domain\Supplier\Contracts\SupplierAdapter`. Nothing above it changes.

## Stack

| Component | Version | Notes |
|---|---|---|
| PHP | `^8.2` | Constrained by the cPanel launch target |
| Laravel | `^12.0` | See the note below about Laravel 13 |
| Filament | `^4.0` | Admin panel only |
| Livewire | `^3.6` | Storefront interactivity |
| Tailwind CSS | `^4` | Compiled at deploy time; no Node at runtime |
| Database | MySQL 8 / MariaDB 10.6+ | SQLite in-memory for tests |

**Why Laravel 12 and not 13.** Laravel 13 requires PHP `^8.3`. The launch
host is cPanel shared hosting, where PHP 8.2 is still a common default and
the PHP version is not always the store owner's to choose. Laravel 12 runs
on 8.2, so deployment is never blocked on a hosting upgrade. Once the host
is confirmed on PHP 8.3+, upgrading is a routine framework upgrade — see
`docs/integration-notes.md`.

## Hosting requirements

Before installing, confirm the host provides all of these. If any is
missing, say so rather than working around it; several have no safe
workaround.

- PHP 8.2 or newer (CLI **and** web, same version)
- Extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`,
  `gd` (or `imagick`), `hash`, `intl`, `mbstring`, `openssl`, `pcre`,
  `pdo_mysql`, `session`, `tokenizer`, `xml`, `zip`
- Composer 2
- MySQL 8.0+ or MariaDB 10.6+
- HTTPS with a valid certificate (payments and webhooks require it)
- Cron, at one-minute or five-minute resolution
- Outbound HTTPS to the supplier and payment providers
- Writable `storage/` and `bootstrap/cache/`
- Working outbound SMTP
- The ability to point a domain at a subdirectory, so only `public/` is
  web-accessible

Check your PHP extensions with:

```bash
php -m
```

## Getting started locally

```bash
composer install
cp .env.example .env
php artisan key:generate

# SQLite is fine for local work.
touch database/database.sqlite
# then set DB_CONNECTION=sqlite in .env

php artisan migrate
php artisan db:seed                  # settings, roles, market, taxonomy, content
php artisan db:seed --class=DemoSeeder   # ...plus sample products (never in production)

php artisan storage:link
npm install && npm run build

php artisan petstore:make-admin --role=owner
php artisan serve
```

The storefront is at `/`, the admin panel at `/admin`.

There is **no seeded default administrator and no default password.**
`petstore:make-admin` prompts for one and enforces a strong policy.

## Demo mode

Every integration has its own mode — `demo`, `sandbox` or `live` — held in
the database and changed in the admin panel.

Out of the box the supplier is in `demo` and only the simulated payment
method is enabled, so the complete customer and admin journey works before
any credentials exist. A banner says so on every page.

The rules that keep this safe:

- A demo or sandbox payment can **never** be fulfilled through a live
  supplier, and a live payment can never be fulfilled through the demo
  adapter. Mixed-mode attempts are refused and logged.
- A live integration that fails **never** silently falls back to demo.
  A failure surfaces as a failure.
- Demo orders are excluded from revenue reporting.
- The demo catalogue seeder refuses to run in production.

The demo supplier reproduces the awkward cases on demand, selected by the
order number — see `docs/demo-scenarios.md`.

## Running the tests

```bash
php artisan test
```

All external calls are mocked or served by the demo adapter, so the suite
needs no network access and no credentials.

For a **real** connectivity check against your supplier account — read-only,
places no order and spends nothing:

```bash
php artisan cj:verify
```

That distinction matters: passing tests prove this code handles a shape of
response correctly. They prove nothing about whether the live API behaves
that way. See `docs/integration-notes.md`.

## Documentation

| Document | What it covers |
|---|---|
| `docs/integration-notes.md` | What is verified, what is not, and what you must check with each provider |
| `docs/deployment-cpanel.md` | Deploying to cPanel, including cron-driven queue workers |
| `docs/vps-migration.md` | Moving to a VPS without rewriting business logic |
| `docs/admin-guide.md` | Day-to-day operation |
| `docs/live-launch-checklist.md` | Everything that must be true before taking real money |
| `docs/demo-scenarios.md` | Reproducing failure cases on demand |
| `PROGRESS.md` | Build state and what remains |

## Things this application deliberately will not do

These are design decisions, not omissions:

- It does not advertise a universal delivery time. Estimates come from the
  carrier for the actual destination and warehouse, keep their unit and
  type, and say "unavailable" when the carrier publishes nothing.
- It does not treat unknown stock as available. "We do not know" and
  "none left" are distinct states everywhere.
- It does not ship from an overseas warehouse because domestic stock ran
  out, unless an administrator deliberately enables it.
- It does not mark an order paid from a browser redirect.
- It does not charge a different currency from the one the shopper saw.
- It does not enable a live payment method whose merchant eligibility has
  not been recorded as verified.
- It does not retry a supplier order whose outcome is unknown. It
  reconciles by reference first.
- It does not call a margin figure "profit" when advertising and overhead
  are not known to it.
- It does not fabricate reviews, certifications, addresses or legal text.
  The seeded policy pages are explicitly marked as drafts for review.

## Licence

Proprietary. All rights reserved.
