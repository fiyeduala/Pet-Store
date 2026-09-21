# Admin guide

How to run the shop day to day.

---

## Signing in

`https://your-domain.com/admin`. Accounts are created from the command
line — there is no default administrator and no default password:

```bash
php artisan petstore:make-admin --role=owner
```

### Roles

| | Owner | Operations | Support |
|---|:---:|:---:|:---:|
| View and edit products | ✓ | ✓ | view only |
| Import from supplier | ✓ | ✓ | |
| Pricing rules | ✓ | ✓ | |
| View orders | ✓ | ✓ | ✓ |
| Approve orders | ✓ | ✓ | |
| Submit to supplier | ✓ | ✓ | |
| **Authorise supplier payment** | ✓ | | |
| Request a refund | ✓ | ✓ | ✓ |
| **Approve a refund** | ✓ | | |
| **Payment gateway settings** | ✓ | | |
| **Staff management** | ✓ | | |
| Brand settings | ✓ | | |
| Returns and enquiries | ✓ | ✓ | ✓ |

Everything that moves money or changes who has access is owner-only.

Turn on two-factor authentication from your profile. Once everyone has
enrolled, set `ADMIN_REQUIRE_MFA=true`.

---

## The daily routine

1. **Exception queue** (Orders → Exception queue). Anything needing a
   human decision lands here with a suggested next step. Aim to leave it
   empty.
2. **Awaiting approval** (Orders). Paid orders waiting for you.
3. **Health** (System → Health). Confirm both heartbeats are green and
   failed jobs are zero.

---

## Understanding an order

An order carries **five independent states**. This is the single most
important thing to understand, because collapsing them is how shops lose
money.

| State | Means |
|---|---|
| Customer payment | Whether *you* have been paid |
| Admin approval | Whether *you* have cleared it to be ordered |
| Supplier order | Whether *CJ* has the order |
| Supplier payment | Whether *you have paid CJ* |
| Shipment | Whether it has physically moved |

A customer paying tells you nothing about the other four. An order sitting
at "paid" and "supplier not paid" is normal and correct.

### Approving

Approving marks an order ready to be sent to the supplier. **It does not
pay the supplier.** The approval dialog says so, and offers two separate
extra tick boxes — submit now, and authorise the charge — both off by
default.

### Submitting

Submission re-checks stock, cost and carrier service first, because the
customer paid some time ago and the world may have moved. If something
material has changed the order goes **on hold** rather than being
submitted, and an exception explains what changed. Nobody is silently
overcharged and no delivery promise is quietly altered.

### When submission times out

You will see the order in "needs reconciliation" and a critical exception
saying **do not resubmit**.

This is the dangerous case: CJ may or may not have created the order. Use
**Reconcile with supplier**, which looks it up by our own reference. If it
exists, the order is attached and nothing is duplicated. If it genuinely
does not, the order becomes safe to submit for the first time.

Never work around this by submitting again. That is how one order becomes
two purchases.

### Paying the supplier

A separate action with its own confirmation, available only to an owner.
It spends real money from your CJ balance. The customer's payment went to
your PayPal or Paystack account — it does not flow to CJ by itself.

If the balance is too low, the order goes to the exception queue rather
than failing quietly. Top up, then retry from the order.

---

## Products

### Importing

Catalogue → Import from supplier. Search, then import individual products.
Everything arrives as a **draft**; nothing publishes itself and there is
no bulk import.

On import the variants are priced by your rules so the draft shows a
realistic figure.

### What a sync will and will not overwrite

The moment you edit a product field in the admin panel, it becomes yours
and a later supplier sync leaves it alone. That covers the name,
subtitle, description, materials, care instructions, "suitable for", SEO
fields and slug.

Images marked **curated** also survive a sync. Supplier images are only
used while you have none of your own.

The supplier always owns cost, dimensions, weight and stock. Those are
facts about their product, and they are refreshed every sync.

### Pricing

Rules apply in order of specificity: **product beats category beats market
beats global.** Within the same level, the lower priority number wins.

Three strategies, and the difference between two of them matters:

- **Fixed markup** — cost plus a fixed amount.
- **Percentage markup** — a percentage *added to cost*. 100% markup on a
  $10 cost gives $20.
- **Target margin** — a percentage *of the retail price*. A 60% margin on
  a $10 cost gives $25, which is a 150% markup.

Markup and margin are reported separately everywhere so they cannot be
confused.

A **manual price override** on a variant beats every rule and stays until
you clear it. Repricing never touches it.

The **contribution** column shows retail minus cost, estimated shipping,
packaging and gateway fees. It turns red when it breaches the floor on the
matching rule. It is deliberately not called profit — advertising and
overhead are not known to the application.

### Stock

Stock is held per warehouse and never merged into one number, because "20
in New Jersey" and "20 in Guangzhou" mean different things for a US order.

Three states, not two:

- **In stock** — a warehouse reported a usable number.
- **Out of stock** — a warehouse reported zero.
- **Availability unknown** — nobody reported anything.

Unknown is never treated as available. A reading past its freshness window
reverts to unknown rather than being trusted indefinitely.

The **safety buffer** (Configuration → Fulfilment) holds back a few units
per warehouse so a slightly stale reading does not oversell.

---

## Shipping and delivery wording

Delivery estimates come from the carrier for the actual destination and
warehouse. The application will not state a universal delivery time,
because there is not one.

What is stored with every estimate: the range, whether they are business
or calendar days, whether it is transit or total time, and where it came
from. If the carrier published nothing, the storefront says the estimate
is unavailable instead of inventing one.

If a carrier gives a number without saying which kind of day it means, the
storefront says so. That looks awkward and is deliberate: over a week the
two differ by about 40%.

You can set an **owner policy estimate** on a shipping rate for the case
where the carrier publishes nothing. It is shown labelled as your own
estimate, not the carrier's.

### Split shipments

Items stocked in different warehouses ship separately. The shopper is told
before paying, sees each parcel's estimate, and gets separate tracking.

A flat rate or free-shipping threshold applies to the **order**, not to
each parcel, so a two-warehouse order is not billed twice.

---

## Packaging

Standard supplier packaging is the default and the only thing promised to
customers.

Branded packaging is a box placed around the product, keeping the
manufacturer's labels intact. It progresses through: planned → awaiting
approval → approved → stocking → available.

**Uploading a design does not make it available.** It becomes usable only
at `available` with a confirmed quantity at the fulfilling warehouse. The
admin panel refuses to mark it available without one.

CJ's API is not assumed to support selecting packaging. Until you confirm
it does and record the capability, arrange it manually with your agent and
note the arrangement on the record.

If branded packaging runs short, the shortage policy decides: fall back to
standard, or hold the order. Either way, what actually shipped is recorded
on the fulfilment.

---

## Refunds, cancellations and returns

**A refund request is not a refund.** Requesting records the intent;
processing sends it to the provider; only a provider confirmation marks it
completed. The status says which.

**Cancelling races dispatch.** Before submission it is guaranteed. After
submission it is not, and the application says so plainly rather than
promising something it cannot deliver. It raises an exception so someone
contacts CJ and then either confirms the cancellation or converts it into
a return.

Returns run: requested → approved → awaiting return → received → refunded
→ closed. Record the supplier claim reference when you raise one with CJ.

---

## Brand

Content → Brand & store details. Saving repaints the storefront, new
emails, metadata and new invoices immediately.

**Orders already placed keep the branding they were issued with.** A
rebrand does not rewrite history, which is what you want when someone
queries an old receipt.

---

## Automation

Configuration → Fulfilment. Three switches, all off:

1. **Approve paid orders automatically** — skips the approval queue.
   Places and pays for nothing on its own.
2. **Submit approved orders automatically** — creates the supplier order.
   Still runs the pre-submission checks and still holds anything that
   changed materially.
3. **Pay the supplier automatically** — **spends money without a human.**

Turn them on one at a time, in that order, and only after the previous one
has run cleanly for a while. The per-order value limit and daily limit
apply regardless: anything above them always waits for a person.

---

## Reporting

The dashboard shows the last thirty days:

- **Orders paid** — excludes demo orders entirely.
- **Net revenue** — excludes tax, discounts and refunds.
- **Contribution** — before advertising and overhead, and it names how
  many orders were left out for incomplete cost data.

Contribution is never labelled profit. Advertising and overhead are not
known to the application, and an order's actual gateway fee only becomes
known once the provider reports it.

---

## Demo mode

While any integration is not live, a banner appears on every storefront
page. Demo orders are marked, excluded from reporting, and cannot be
fulfilled through a live supplier.

To walk the whole journey without credentials, leave the supplier in demo
mode and the simulated payment method enabled. See
`docs/demo-scenarios.md` for reproducing failures on demand.

Before launch: disable the simulated payment method and switch the
supplier to live.
