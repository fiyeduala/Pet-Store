# Live launch checklist

Everything that must be true before this shop takes real money.

Work top to bottom. The first three sections are hard blockers — the store
cannot legitimately trade without them. Nothing here is ticked for you:
each item needs a person to check and record it.

Admin → System → Health shows the automatable subset of this list live.

---

## 1. Payment — can you actually get paid?

- [ ] **PayPal has confirmed in writing** that this account can accept
      commercial Checkout payments from US customers in USD via the REST
      API. See `docs/integration-notes.md` for the exact question to ask.
      A personal account, or an account registered in a country PayPal
      restricts from receiving merchant payments, **cannot** be used.
- [ ] If PayPal cannot serve this business, an alternative provider is in
      place, or the entity has been registered somewhere PayPal supports.
      *(Do not misrepresent an account's country or type. It breaches the
      provider's terms and risks the money and the business.)*
- [ ] Paystack (if used) has confirmed in writing which currencies this
      account can charge and settle in.
- [ ] Every enabled live method has its currencies recorded in
      Admin → Payment methods, and **only** currencies the provider
      confirmed.
- [ ] `is_verified` is ticked on each live method, with who verified it
      and when.
- [ ] The simulated payment method is **disabled**.
- [ ] Live credentials are in `.env` on the server, not in the repository,
      and `.env` is `chmod 600` and outside the web root.
- [ ] Webhook URLs registered with each provider, and `PAYPAL_WEBHOOK_ID`
      set. *(Without it, no PayPal event can be verified, so none will be
      processed.)*
- [ ] One real low-value payment taken end to end, then refunded, and both
      confirmed in the provider dashboard.

## 2. Supplier — can you actually fulfil?

- [ ] CJ endpoint paths checked against the official API reference and
      corrected in `config/petstore.php` where they differ. **They have
      not been verified by this build.**
- [ ] CJ credentials entered in Admin → Integrations.
- [ ] `php artisan cj:verify` passes every check against the live account.
- [ ] The integration mode is `live`.
- [ ] One live test order placed, submitted, paid and tracked, as set out
      in `docs/integration-notes.md`. Confirm it appears in the CJ
      dashboard **exactly once**.
- [ ] Your CJ account balance is funded. An unfunded balance stops every
      order at the supplier payment step.
- [ ] Warehouses reviewed in the admin panel: US warehouses enabled,
      overseas warehouses disabled unless you have deliberately decided to
      ship from them and will disclose it.
- [ ] Stock has synced at least once and Health shows a recent sync.
- [ ] Observed capabilities recorded on the supplier record. Leave
      packaging selection and webhooks **off** unless CJ has confirmed
      them.

## 3. Products and pricing

- [ ] Every published product has been reviewed by a person: title,
      description, images, dimensions and materials.
- [ ] No product makes a certification, safety or performance claim you
      cannot evidence.
- [ ] No product claims US manufacture. Stocked in a US warehouse is a
      statement about where it ships from, not where it was made.
- [ ] Pricing rules reviewed, and the contribution shown on a few
      variants sanity-checked against what you expect to keep.
- [ ] The contribution floor is set high enough to absorb your shipping
      subsidy and gateway fees.
- [ ] No sample reviews are published. *(Sample rows are flagged
      `is_sample` and never render, but confirm.)*
- [ ] Demo products removed, or the store was seeded without them.

## 4. Shipping

- [ ] Shipping zones reviewed for the contiguous US, Alaska, Hawaii, the
      territories and military addresses.
- [ ] You have decided and configured what happens for PO boxes.
- [ ] The charging mode is what you intend: quoted, flat, or free above a
      threshold.
- [ ] If free shipping above a threshold: you have checked the threshold
      leaves a positive contribution on a typical qualifying basket, and
      you understand it is evaluated **after** discounts.
- [ ] Handling time in Admin → Fulfilment matches how fast you actually
      review and submit orders.
- [ ] A test ZIP in each zone returns sensible services and prices.
- [ ] The shipping policy page describes what the store actually does.

## 5. Tax

- [ ] **An accountant has advised on where this business has a sales tax
      obligation.** The application does not and cannot determine this.
- [ ] Tax mode set accordingly in Admin → Markets, with rules configured
      and marked active if tax is due.
- [ ] If tax is switched off, that is a deliberate, advised decision, not
      an oversight.
- [ ] Who configured it and when is recorded on the tax rules.

> This application makes no claim of automatic US sales tax compliance.
> It applies the rules you configure and reports plainly when none match.

## 6. Policies and content

- [ ] Shipping, Returns, Privacy and Terms pages **completed and
      reviewed**. They ship as drafts with explicit `[OWNER TO COMPLETE]`
      placeholders.
- [ ] "Still a draft awaiting review" turned off on each one. While it is
      on, customers see an under-review notice.
- [ ] Privacy and Terms reviewed by someone qualified. The drafts are a
      structural starting point, not legal advice.
- [ ] Return arrangements agreed with CJ: return address, who pays return
      shipping, the window, and what happens to returned stock.
- [ ] Business details filled in: legal entity name, registered address,
      registration number. These appear on invoices and must be accurate.
- [ ] Contact details and support hours set, and the support inbox is
      monitored.
- [ ] About page completed.
- [ ] FAQ reviewed for anything that is no longer true.

## 7. Packaging

- [ ] Packaging records reflect reality. Standard supplier packaging is
      the default and the only thing promised to customers.
- [ ] Branded packaging, if planned, is at its true state — `planned`,
      `awaiting_approval`, `approved`, `stocking` or `available`.
- [ ] Nothing is marked `available` without confirmed stock at the
      warehouse. *(The admin panel refuses this, but confirm.)*
- [ ] No customer-facing copy promises branded packaging until it is
      genuinely available.
- [ ] The shortage policy is what you want: fall back to standard, or hold
      the order.

## 8. Brand and storefront

- [ ] Store name decided and set. *("Pet Store" is a placeholder.)*
- [ ] Logos uploaded for light and dark backgrounds, plus favicon and app
      icon.
- [ ] Accent colour set, and checked for contrast against white text on a
      real button.
- [ ] SEO defaults written.
- [ ] Home sections reviewed and reordered.
- [ ] Navigation links all resolve.
- [ ] The storefront checked at phone, tablet and desktop widths.
- [ ] Checked with the keyboard alone: every control reachable, focus
      always visible.

## 9. Email

- [ ] SMTP configured and a real message received, not just accepted.
- [ ] `MAIL_FROM_ADDRESS` is on your own domain.
- [ ] SPF, DKIM and DMARC set up. Without them, receipts land in spam.
- [ ] Each template checked in a real client: order paid, dispatched,
      cancelled, refund, guest tracking link.
- [ ] Branding in emails matches the storefront.

## 10. Operations

- [ ] Scheduler cron installed; Health shows the heartbeat healthy.
- [ ] Queue worker cron (cPanel) or Supervisor (VPS) running; Health shows
      the heartbeat healthy.
- [ ] You understand the queue latency on your host, and it is acceptable.
- [ ] Failed jobs are zero, and you know where to look when they are not.
- [ ] **All three automation switches are off** for launch. Approve orders
      by hand until you trust the flow.
- [ ] You know the difference between approving an order and paying the
      supplier, and that approving does not spend money.
- [ ] The exception queue is empty, and someone checks it daily.

## 11. Security

- [ ] `APP_DEBUG=false` and `APP_ENV=production`.
- [ ] HTTPS enforced; `SESSION_SECURE_COOKIE=true`.
- [ ] `https://your-domain.com/.env` returns 403 or 404.
- [ ] Only `public/` is web-accessible.
- [ ] Every staff account has its own login. No shared accounts.
- [ ] Roles assigned at least privilege: support cannot authorise a
      supplier payment, approve a refund or change gateway settings.
- [ ] Two-factor authentication enrolled on every account that can move
      money. Consider `ADMIN_REQUIRE_MFA=true`.
- [ ] `APP_KEY` backed up somewhere other than the server.

## 12. Backups

- [ ] Nightly database backup running.
- [ ] `storage/app/public` included.
- [ ] Backups stored off the server.
- [ ] **A restore has been tested into a staging database.** An untested
      backup is a hope.
- [ ] `APP_KEY` stored with the backups, separately from the server.

---

## The first week

- [ ] Check the exception queue every day.
- [ ] Check Health every day.
- [ ] Read every order before approving it.
- [ ] Compare the contribution the admin panel reports against what
      actually landed in your account.
- [ ] Only after a couple of clean weeks, consider turning on automatic
      approval. Leave automatic supplier payment until last.

---

## Outstanding owner inputs

Questions only the store owner can answer. None has been guessed.

| # | Input needed | Blocks |
|---|---|---|
| 1 | Final store name, logos, accent colour | Brand identity (placeholder in use) |
| 2 | PayPal's written answer on merchant eligibility for USD | Taking any payment |
| 3 | Paystack's written answer on supported currencies | Offering Paystack |
| 4 | CJ account email and API key | Any live supplier operation |
| 5 | Accountant's advice on sales tax obligations | Charging tax correctly |
| 6 | Legal entity name, registered address, registration number | Invoices and policy pages |
| 7 | Return address and who pays return shipping | Returns policy |
| 8 | Support email, phone and hours | Contact page and emails |
| 9 | Whether overseas fulfilment is ever acceptable, and the wording | Out-of-stock handling |
| 10 | Free shipping threshold, or the flat rate | Shipping charges |
| 11 | Review and completion of the four policy drafts | Legal readiness |
| 12 | Confirmation the host meets every requirement in the README | Deployment |
