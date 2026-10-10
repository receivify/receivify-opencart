# Receivify for OpenCart

[![CI](https://github.com/receivify/receivify-opencart/actions/workflows/ci.yml/badge.svg)](https://github.com/receivify/receivify-opencart/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/receivify/receivify-opencart)](https://github.com/receivify/receivify-opencart/releases/latest)
[![License: MIT](https://img.shields.io/badge/license-MIT-a3e635.svg)](LICENSE)

Accept Bitcoin, Ethereum, USDT, USDC and other crypto in your OpenCart store with [Receivify](https://receivify.io).
Customers pay on a hosted checkout, the payment is checked on the blockchain, and the money is forwarded
straight to **your own wallet**, minus a 1% fee. Non-custodial, no ID checks, signed webhooks mark orders paid.

This repository is the full, readable source of the official plugin, so you can audit exactly what runs in your store.

## Requirements

- OpenCart 3.0.x (also loads on 2.3.x)
- PHP 7.4 or newer
- A Receivify account (free) with an API key and webhook secret

## Install

1. Download `receivify-opencart.zip` from the [latest release](https://github.com/receivify/receivify-opencart/releases/latest)
   (or from [receivify.io/plugins/receivify-opencart.zip](https://receivify.io/plugins/receivify-opencart.zip)).
2. Unzip `receivify-opencart.zip` and copy the contents of `upload/` into your OpenCart root (or install it via **Extensions → Installer**).
3. Go to **Extensions → Extensions → Payments**, install **Receivify**, then **Edit** it.

## Configure

In your Receivify dashboard create an **API key** (Dashboard → API keys) and copy your **webhook secret**
(Dashboard → Webhooks), then paste both into the plugin settings. The webhook URL (`index.php?route=extension/payment/receivify/callback`) is sent with every invoice automatically, so there is nothing to paste.

## Test before going live

1. In the Receivify dashboard open **Webhooks → Send test** and check your store accepts the signed message.
2. Place one small real order at the coin's minimum (a stablecoin on a cheap network costs a few cents in fees),
   pay it from your own wallet, and watch the order move to paid when the webhook lands.

## Support

- Documentation: https://receivify.io/docs
- Integration guide: https://receivify.io/integrations/opencart
- Help: https://receivify.io/support
- Issues with this plugin: [open an issue](https://github.com/receivify/receivify-opencart/issues)

## Security

Please report vulnerabilities privately to **security@receivify.io** (see our
[disclosure policy](https://receivify.io/bug-bounty)). Do not open public issues for security problems.

## License

MIT. See [LICENSE](LICENSE).

---

## Detailed guide

Accept Bitcoin & crypto in OpenCart via Receivify. Non-custodial: funds settle to
your own payout addresses; the store only ever sees a hosted checkout URL and a
signed webhook.

Targets **OpenCart 3.0.x** (also loads on 2.3.x — see *Compatibility* below).

## How it works

1. At the checkout payment step the customer clicks **Confirm order**. The
   catalog controller creates a **fiat-priced** invoice
   (`POST /api/v1/invoices` with `amount_fiat` + the order currency) and moves
   the order to your "Awaiting payment" status.
2. The customer is redirected to Receivify's hosted checkout and pays.
3. Receivify POSTs a **signed** webhook to
   `https://yourstore/index.php?route=extension/payment/receivify/callback`.
   The plugin verifies the HMAC, de-duplicates on `(event, sequence)`, and only
   then writes order history.

All HTTP and HMAC work is the shared [PHP SDK](../php-sdk/README.md), vendored at
`system/library/receivify/Receivify.php`. This module contains no HTTP client and
no crypto of its own.

## Install

1. Copy the **contents** of `upload/` over your OpenCart root, so you end up with:

   ```
   admin/controller/extension/payment/receivify.php
   admin/language/en-gb/extension/payment/receivify.php
   admin/view/template/extension/payment/receivify.twig
   catalog/controller/extension/payment/receivify.php
   catalog/language/en-gb/extension/payment/receivify.php
   catalog/model/extension/payment/receivify.php
   catalog/view/theme/default/template/extension/payment/receivify.twig
   system/library/receivify/Receivify.php
   ```

   No core files are modified, so there is no OCMOD to apply.

2. Admin → **Extensions → Extensions → Payments** → find **Receivify** → click
   the green **+** (install). This runs `install()`, which creates the
   `oc_receivify_event` table used for webhook de-duplication.

3. Click **Edit** and fill in:
   - **Receivify API URL** — e.g. `https://receivify.io`
   - **API key** — your `rcv_live_…` key (dashboard → API keys)
   - **Webhook secret** — your `whsec_…` secret
   - **Settlement coin** — must be enabled *and* settleable in Receivify admin → Assets
   - **Order statuses** — see the mapping table below
   - **Status** → Enabled

4. Copy the read-only **Webhook / callback URL** shown on the settings page into
   Receivify as a webhook endpoint. (The same URL is also sent as the
   per-invoice `callback_url`, so payments work even before you register an
   account-level endpoint.)

5. Admin → System → Users → User Groups: make sure your group has modify
   permission for `extension/payment/receivify`.

6. In your Receivify dashboard open **Webhooks → Send test** to confirm your store accepts the signed message, then place one small real order at the coin's minimum (a stablecoin on a cheap network costs a few cents in fees) and pay it from your own wallet.

## Order-status mapping

| Receivify event | Plugin action | Configurable status |
|---|---|---|
| *(invoice created)* | order history comment | **Awaiting payment status** (default: Pending) |
| `invoice.confirming` | comment, no release | **Confirming status** (default: Pending) |
| `invoice.paid`, `invoice.swept`, `sweep.sent` | order marked paid, customer notified | **Paid status** (default: Complete) |
| `invoice.partial`, `invoice.partial_expired` | comment naming received vs expected base units, status unchanged | — |
| `invoice.expired`, `invoice.cancelled` | order cancelled | **Expired / cancelled status** (default: Cancelled) |
| `invoice.failed` | order failed | **Failed status** (default: Failed) |
| `test`, `forward.*`, anything unknown | acknowledged with 200, nothing changed | — |

Unknown event names are deliberately ignored rather than treated as an error:
adding an event name is an additive change to the API.

## Webhook security

- `X-Receivify-Signature: t=<unix>,v1=<hex>` where
  `v1 = HMAC-SHA256(secret, "<t>.<rawBody>")`.
- The plugin verifies against the **raw** request body (`php://input`) — never
  re-encoded JSON — in constant time, and rejects a timestamp more than 300
  seconds out.
- A failed verification answers **401** and writes nothing.
- **Rotation:** when you rotate the secret, put the new one in *Webhook secret*
  and the old one in *Previous webhook secret*. Receivify signs with the current
  secret, but the previous one stays acceptable until its grace window closes
  (24h by default), so a receiver must accept either. Clear the previous field
  afterwards.

## Idempotency

- **Outbound:** `reference` and `Idempotency-Key` are both
  `oc-<store token>-<order_id>` (token stored under setting code
  `receivify_internal`, which survives saving the settings and uninstalling).
  The store token is random, created once per install and never changed, so two
  shops on one Receivify account (or a reinstalled shop that restarts its order
  numbers) never collide. Webhooks carrying another shop's token are
  acknowledged and ignored; bare references from older invoices still work.
  The API also uses `reference` as a de-duplication key. A customer who
  double-clicks Confirm gets the same invoice, not a second one.
- **Inbound:** every delivery is claimed in `oc_receivify_event` keyed on
  `"<event>:<sequence>"` before anything is written. A retry or a manual replay
  is answered `200 duplicate` and changes nothing. The table survives an
  uninstall on purpose, so a reinstall cannot replay history.

## Compatibility

- Written against **OpenCart 3.0.x** conventions (`payment_receivify_*` setting
  keys, `extension/payment/…` routes, Twig templates).
- `addHistory()` / `addOrderHistory()` is resolved with `method_exists()`, so the
  callback also works on OpenCart 2.3.x. The 2.3 admin UI uses `.tpl` templates
  rather than `.twig` — on 2.3 you would need to rename the two templates and
  convert the Twig syntax.
- OpenCart 4.x uses a different namespace and folder layout entirely
  (`extension/receivify/…`, `Opencart\Catalog\Controller\…`); this module will
  **not** load there without a rewrite.

## Not verified

This module has **not** been installed or run against a live OpenCart instance.
It is written to OpenCart 3.0.x conventions and every PHP file passes `php -l`,
but the following have not been exercised in a browser:

- the admin settings form rendering and saving (Twig template variables),
- the checkout payment-step button and its AJAX round trip,
- `install()` actually being called by the extension installer on your version,
- the exact order-status transitions in your store's status set.

Test on a staging store first: send a test webhook from the dashboard, then take one small real payment before going live.
