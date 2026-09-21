# Demo scenarios

The demo adapters are deterministic: the same input always produces the
same output. They exist so the whole application can be exercised — the
awkward paths included — without credentials, network access or money.

---

## Turning demo mode on

Demo mode is per integration, in the database.

- **Supplier**: Admin → Integrations → CJdropshipping → mode `demo`.
- **Payments**: Admin → Payment methods → "Simulated payment (demo)"
  enabled. Live methods ship disabled.

A banner appears on every storefront page while anything is not live.

The isolation rules that make this safe:

- a demo or sandbox payment can never be fulfilled through a live
  supplier, and vice versa; mixed-mode attempts are refused and logged;
- a live integration that fails never falls back to demo;
- demo orders are excluded from revenue reporting;
- the demo catalogue seeder refuses to run in production.

---

## Seeding sample data

```bash
php artisan migrate:fresh
php artisan db:seed --class=DemoSeeder
```

Eight products across toys, enrichment, feeding, grooming, travel and
beds, imported through the **real** supplier import path and priced by the
**real** pricing rules.

---

## Payment scenarios

Selected by the end of the order number. Order numbers are generated, so
to force one you will normally place the order and then edit the number in
the database — or rely on the tests, which do exactly this.

| Order number ends | What happens |
|---|---|
| `-DECLINE` | The simulated payment is declined. The order is not paid. |
| `-PENDING` | The payment stays pending. |
| anything else | The payment succeeds. |

A declined demo payment leaves the order unpaid and shows the customer a
clear message. It never half-succeeds.

---

## Supplier scenarios

Selected by a marker anywhere in the order number, which carries through
to the fulfilment reference.

| Order number contains | What happens |
|---|---|
| `-TIMEOUT` | Submission times out with an **unknown outcome**. The order goes to `needs_reconciliation` with a critical exception saying not to resubmit. Reconciling finds the order the demo supplier did in fact record. |
| `-OOS` | Submission is rejected: out of stock. A clear failure, not an unknown one. |
| `-NOBAL` | Submission succeeds, then supplier payment fails for insufficient balance, raising an exception. |
| anything else | Everything succeeds. |

The `-TIMEOUT` case is worth walking through by hand at least once. It is
the one that costs real money if handled badly, and it is why the
reconcile-by-reference path exists.

---

## Stock scenarios

Built into the demo catalogue rather than triggered:

| Variant | Behaviour |
|---|---|
| `DEMO-V-1007-UNK` (Treat Dispensing Puzzle Ball) | **No warehouse ever reports a quantity.** The storefront shows "Availability unknown" and refuses to add it to a basket. This is the case most systems get wrong by treating unknown as available. |
| Everything else | Deterministic quantities per warehouse, derived from the variant id. Some warehouses are legitimately zero. |
| `CN-GZ` | Always well stocked, and **disabled by default**, so you can prove that running out of US stock does not silently ship from China. |

To see a **split shipment**: find two variants where one has US-NJ stock
and the other only US-CA (the seeded data produces several such pairs),
put both in a basket, and check out. The delivery step will show two
parcels with separate services and estimates.

---

## Delivery estimate scenarios

The demo supplier deliberately returns three services with different
estimate quality, so all three wordings can be seen at once:

| Service | Estimate |
|---|---|
| Demo US Standard | 3–7 **business days** in transit |
| Demo US Expedited | 2–4 **days** (calendar) in transit |
| Demo Economy | **No estimate published** — the storefront says so |

Enter a ZIP starting `99` (Alaska) or `96` (Hawaii) to see the remote
surcharge and longer transit. Enter a state of `AE` at checkout to see an
excluded destination block the order with a reason.

---

## Walking the full journey

1. Browse the shop, filter by pet type, open a product.
2. Enter ZIP `07101` in the estimator. Note the three services and that
   one honestly reports no estimate.
3. Add to basket, open the drawer, change the quantity.
4. Check out as a guest. Note the delivery step, the simulated-payment
   label, and the currency stated before paying.
5. Settle the simulated payment.
6. On the confirmation page, note that "paid" is not presented as
   "shipped".
7. In admin, open the order. All five states are shown separately.
8. Approve it, reading the dialog: approval does not pay the supplier.
9. Submit it. Watch the pre-submission checks run.
10. Pay the supplier, reading the confirmation: this spends money.
11. Refund part of it and note the refund is a request until confirmed.
12. Check Reports: the demo order is excluded.

---

## Adding a scenario

Failure modes live in `App\Domain\Supplier\Adapters\Demo\DemoScenario`.
Add a case, add its marker to `forReference()`, and handle it in
`DemoSupplierAdapter`. Keep it deterministic — no randomness, so a test
can rely on it.
