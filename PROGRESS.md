# Pet Store — Build Progress

Living progress file. Update at the end of every working session.

## Stack decision (recorded 2026-09-21)

| Component | Version | Why |
|---|---|---|
| PHP | `^8.2` (dev container runs 8.4.19) | Laravel 13 requires PHP `^8.3`. The launch host is cPanel shared hosting where 8.2 is still a common default, so pinning to `^8.2` keeps deployment unblocked. |
| Laravel | `^12.0` | Still in the supported window; compatible with PHP 8.2. Upgrade path to 13 documented in `docs/integration-notes.md`. |
| Filament | `^4.0` (resolved v4.13.4) | Admin panel only. Requires `illuminate ^11.28\|^12.0\|^13.0`. |
| Livewire | `^3.6` (resolved v3.8.9) | Storefront interactivity + Filament dependency. |
| Tailwind CSS | `^4` | Storefront styling. Built at deploy time; no Node at runtime. |
| Database | MySQL/MariaDB (SQLite for tests) | |

Resolution was verified with `composer update --dry-run` before committing to the stack.

## Phases

- [ ] 0. Scaffold + configuration
- [ ] 1. Domain migrations & models
- [ ] 2. Money handling & pricing engine
- [ ] 3. Supplier adapters (CJ live + demo)
- [ ] 4. Payment gateway adapters (PayPal, Paystack, demo)
- [ ] 5. Shipping & tax services
- [ ] 6. Storefront
- [ ] 7. Filament admin
- [ ] 8. Order lifecycle, jobs, exception queue
- [ ] 9. Notifications & email templates
- [ ] 10. Seeders & demo data
- [ ] 11. Tests
- [ ] 12. Deployment & handover docs

## Open owner inputs

Tracked in `docs/live-launch-checklist.md`.
