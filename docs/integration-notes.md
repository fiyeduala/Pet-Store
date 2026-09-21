# Integration notes

What is verified, what is not, and what you must confirm yourself.

This document exists because "the tests pass" and "the integration works"
are different claims. Everything in the automated suite uses mocked calls
or the demo adapter. **No test in this repository proves that any live
third-party API behaves the way this code expects.**

---

## Verification status at a glance

| Integration | Code written | Exercised against live account | Safe to enable? |
|---|---|---|---|
| CJdropshipping — authentication | Yes | **No** | Run `cj:verify` first |
| CJdropshipping — catalogue & variants | Yes | **No** | Run `cj:verify` first |
| CJdropshipping — warehouse stock | Yes | **No** | Run `cj:verify` first |
| CJdropshipping — shipping quotes | Yes | **No** | Run `cj:verify` first |
| CJdropshipping — order creation | Yes | **No** | Needs one live test order |
| CJdropshipping — supplier payment | Yes | **No** | Needs one live test order |
| CJdropshipping — tracking | Yes | **No** | Needs one live shipment |
| CJdropshipping — branded packaging | **Not claimed** | **No** | Assume unsupported |
| CJdropshipping — webhooks | **Not claimed** | **No** | Polling is used instead |
| PayPal — Orders v2 | Yes | **No** | Merchant eligibility unconfirmed |
| Paystack — transactions | Yes | **No** | USD support unconfirmed |
| Demo adapters | Yes | N/A — deterministic | Yes, in demo mode |

---

## CJdropshipping

### The endpoint paths are unverified

`developers.cjdropshipping.com` and its `.cn` mirror were **not reachable
from the environment this application was built in** (blocked by the
network egress policy). The endpoint paths and response field names were
therefore written from CJ's published API 2.0 conventions and from
secondary sources, not read from the official reference.

This was handled by making every path configurable rather than hard-coded.
All of them live in `config/petstore.php` under
`petstore.suppliers.cjdropshipping.endpoints`:

```php
'endpoints' => [
    'access_token'      => 'authentication/getAccessToken',
    'refresh_token'     => 'authentication/refreshAccessToken',
    'product_list'      => 'product/list',
    'product_detail'    => 'product/query',
    'stock_by_variant'  => 'product/stock/queryByVid',
    'freight_calculate' => 'logistic/freightCalculate',
    'order_create'      => 'shopping/order/createOrderV2',
    'order_detail'      => 'shopping/order/getOrderDetail',
    'order_list'        => 'shopping/order/list',
    'balance'           => 'shopping/pay/getBalance',
    'pay_balance'       => 'shopping/pay/payBalance',
    'track_info'        => 'logistic/trackInfo',
],
```

**Your first task before going live** is to open the official reference at
<https://developers.cjdropshipping.com/en/api/api2/> and check each path
and its response field names against the table above. Correcting one is a
config edit, not a code change.

The response mapping lives in
`app/Domain/Supplier/Adapters/CJ/CjSupplierAdapter.php`. Each mapper
accepts several plausible field names (for example `storageNum`,
`stockNum` and `quantity` for a stock figure), so a naming difference
usually degrades to "unknown" rather than to a wrong number. That is
deliberate: a wrong stock figure oversells, an unknown one does not.

### Known API behaviour this code relies on

- **Authentication.** `POST authentication/getAccessToken` with the account
  email and API key. Tokens are long-lived (CJ documents 180 days) and the
  same token is returned for repeated calls within 24 hours. The client
  caches the token and refreshes it well before expiry
  (`token_refresh_margin_hours`, default 48).
- **Rate limiting.** CJ documents **1 request per second** on the token
  endpoint. The client serialises calls through a cache-backed throttle
  and sleeps out the remaining window rather than relying on a 429. The
  limit is configurable per bucket.
- **Response envelope.** `{code, result, message, data}`. A `result` of
  `true` or a `code` of `200`/`0` is treated as success; anything else
  raises a typed exception. A message mentioning the token invalidates the
  cached token and raises an authentication failure.

### Delivery estimates

CJ's freight response carries an "ageing" field such as `"7-15"`. It does
**not** consistently state whether those are calendar or business days.

This application therefore:

- parses the range, and records `business_days` **only** when the payload
  actually says so (`business` or `working`), `days` when it says `day`,
  and `unknown` otherwise;
- treats the ageing figure as **transit time**, not total delivery time,
  and states the store's own handling time separately;
- shows "Carrier quotes 7–15 for transit, but did not specify whether
  these are calendar or business days" when the unit is unknown, rather
  than picking one.

Over a week the two units differ by roughly 40%, which is the difference
between a met and a missed promise. Confirm the unit with your CJ account
manager and, if they confirm it, you can set an owner policy estimate on
the shipping rate to fill the gap — it will be labelled as the store's own
estimate, not the carrier's.

### Operations not claimed as supported

`Supplier::supports()` returns **false** for anything not positively
observed against your account. Two matter:

**Branded packaging.** `capabilities.packaging_selection` defaults to
false. If a fulfilment asks for branded packaging while it is false, the
adapter raises `SupplierOperationUnsupported` carrying a manual workaround
rather than sending a packaging id CJ may ignore. Arrange packaging with
your CJ agent, record the arrangement on the packaging record, and only
set the capability to true once CJ confirms the API can select it.

**Webhooks.** `capabilities.webhooks` defaults to false. The webhook
endpoint at `/webhooks/suppliers/cjdropshipping` records what arrives and
explicitly does **not** act on it while the capability is unverified. The
supported path is polling: `RefreshTracking` runs every thirty minutes.

### The live test order

`cj:verify` covers the read-only surface. Order creation, supplier payment
and tracking cannot be verified without spending money, so they need one
deliberate low-value live order:

1. Switch the supplier to `live` in Admin → Integrations and run
   `php artisan cj:verify` again. Every check must pass.
2. Leave all three automation switches **off**.
3. Place one real order for the cheapest item you stock, to your own
   address.
4. Approve it, then submit it, and check that:
   - a supplier order id comes back and is stored on the fulfilment;
   - the merchandise and shipping costs CJ reports match what you were
     quoted at checkout;
   - the order appears in your CJ dashboard exactly once.
5. Pay the supplier for it and check the balance moved by the expected
   amount, once.
6. When it ships, check the tracking number reaches the order and the
   customer email, and that the carrier's estimate reads sensibly.
7. Record what you found in this file, and set the observed capabilities
   on the supplier record.

If step 4 times out, **do not resubmit**. The order will be marked
`needs_reconciliation`; use Reconcile with supplier on the order, which
looks it up by our reference. This path is covered by automated tests
against the demo adapter, but confirm it behaves the same live.

---

## PayPal

### Merchant eligibility is the blocker, not the code

The adapter implements Orders v2 (create, capture, status) and Payments v2
(refund), with webhook verification through PayPal's own
`verify-webhook-signature` endpoint. The code is complete. Whether *your
account* may use it is a separate question that only PayPal can answer.

The store owner has described a **Nigerian personal PayPal account**. Two
things to establish before assuming that works:

1. **Personal vs business.** Commercial Checkout payments generally
   require a business account. A personal account is not a substitute.
2. **Country and currency.** PayPal's rules on which account countries may
   *receive* merchant payments, and settle in USD, vary and change. Nigeria
   has historically been restricted for receiving.

Ask PayPal support, in writing:

> Can this account accept commercial PayPal Checkout payments from US
> customers, in USD, via the REST Orders v2 API? If not, what type of
> account and which registered country would be required?

Until you have a written answer, leave the method disabled.
`PaymentGateway::isLiveReady()` requires `is_verified`, which an
administrator sets by hand after receiving that answer. Sandbox success
does not set it and cannot.

### What must not be done

Do not misrepresent an account's country or type to pass verification, and
do not route customer payments through a personal transfer mechanism
dressed up as checkout. Both breach the provider's terms and put the money
and the business at risk. If PayPal cannot serve this business, the honest
options are a different provider or a properly registered entity.

### Webhook setup

Once live, add a webhook in the PayPal dashboard pointing at:

```
https://your-domain.com/webhooks/payments/paypal
```

Subscribe to at least `PAYMENT.CAPTURE.COMPLETED`,
`PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED`. Copy the webhook ID
into `PAYPAL_WEBHOOK_ID`. Without it, signature verification cannot run
and every event is rejected — which is the correct failure mode, but it
means nothing will be processed.

---

## Paystack

### Do not assume USD

Paystack accounts are frequently NGN-only. The gateway record ships with
**no** supported currencies, so it cannot be offered for a USD order at
all until someone adds one.

Ask Paystack, in writing:

> Which currencies can this account charge in, and which can it settle in?
> Can it charge US customers in USD?

If the answer is NGN only, leave the method disabled. The store will not
quietly charge NGN for a USD order — the method simply is not offered, and
if no method can take the currency, checkout fails with a clear message.

If you later want to sell in USD but settle in NGN, that needs a
deliberately configured conversion policy: a timestamped rate, the
converted amount disclosed and accepted before payment, and the order
totals frozen. The schema supports this (`presented_amount_minor`,
`presented_currency`, `fx_rate`, `fx_rate_at`,
`currency_disclosure_accepted`) but **the policy is not implemented** —
there is no UI and no conversion service. Building it is a deliberate
project, not a config toggle.

### Webhook setup

```
https://your-domain.com/webhooks/payments/paystack
```

Paystack signs the raw body with HMAC-SHA512 using your secret key; the
adapter verifies this. Paystack does not send a distinct event id, so the
dedupe key is the event name plus the transaction reference.

---

## Supplier payment is not customer payment

Worth stating plainly, because it is a common and expensive
misunderstanding:

- Money from a customer goes to **your** PayPal or Paystack account.
- Money to CJ comes from **your CJ account balance**, which you top up
  separately.
- Nothing routes a customer's payment directly to CJ.

The application models these as two independent state machines with
separate authorisation, separate audit trails and separate toggles. An
order can be `payment_state = paid` and `supplier_payment_state = not_paid`
indefinitely, and that is a normal, correct state.

---

## Upgrading to Laravel 13

Laravel 13 requires PHP `^8.3`. Once the host is confirmed on 8.3 or newer:

1. Confirm both the CLI and web PHP versions with `php -v` and a
   `phpinfo()` page — they are often different on cPanel.
2. Raise `"php": "^8.3"` and `"laravel/framework": "^13.0"` in
   `composer.json`.
3. Check Filament's compatibility for the version you are on; Filament 4
   already declares `illuminate/contracts: ^11.28|^12.0|^13.0`.
4. `composer update`, then run the full test suite.
5. Work through the official upgrade guide for any behavioural changes.

No business logic should need rewriting. Nothing in `app/Domain` depends on
framework internals beyond Eloquent, the container, the HTTP client and
the queue.

---

## Change log for this document

Update this section as you verify things, so the next person knows what is
actually known rather than assumed.

| Date | Who | What was verified | Result |
|---|---|---|---|
| _(not yet)_ | | CJ endpoint paths against the official reference | |
| _(not yet)_ | | CJ live authentication (`cj:verify`) | |
| _(not yet)_ | | CJ live test order | |
| _(not yet)_ | | PayPal merchant eligibility for USD | |
| _(not yet)_ | | Paystack supported currencies | |
