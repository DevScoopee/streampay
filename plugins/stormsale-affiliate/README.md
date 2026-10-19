# StormSale Affiliate for WooCommerce

Standalone WordPress/WooCommerce plugin package that tracks affiliate referral clicks
and posts encrypted, ledger-verified conversions from completed orders to the
merchant's StormSale smart campaign on Stellar.

## Layout

```
plugins/stormsale-affiliate/
├── stormsale-affiliate.php       Plugin bootstrap, constants, activation hook
├── uninstall.php                 Option cleanup on uninstall
├── readme.txt                    WordPress.org readme
├── assets/js/stormsale-referral.js  Cookie capture + sliding-window refresh (vanilla JS)
├── includes/
│   ├── class-stormsale-plugin.php      Hook wiring + asset enqueuing
│   ├── class-stormsale-settings.php    WooCommerce → StormSale settings page
│   ├── class-stormsale-referral.php    Referral cookie / session / order-meta lifecycle
│   ├── class-stormsale-stats.php       Bounded referral click counter
│   ├── class-stormsale-crypto.php      AES-256-GCM envelopes + HMAC-SHA256 signatures
│   ├── class-stormsale-client.php      Encrypted gateway transport
│   ├── class-stormsale-conversion.php  Payment-complete → conversion pipeline
│   └── class-stormsale-order-admin.php Order-details conversion panel
└── tests/
    ├── run.php                   CLI test runner (no WordPress required)
    ├── testkit.php               WordPress/WooCommerce stubs + assertions
    ├── js/referral.test.js       node:test suite for the referral cookie JS
    └── test-*.php                PHP unit suites
```

## Conversion lifecycle

1. `?ss_ref=code` (or `?ref=code`) lands on the store. `StormSale_Referral::capture()`
   validates the code (`[A-Za-z0-9][A-Za-z0-9._-]{0,63}`), writes the HttpOnly
   `stormsale_ref` cookie, mirrors it into the WooCommerce session and counts the
   click. The JS asset performs the same capture client-side and slides the expiry on
   every page view.
2. At checkout the code is written to the order as `_stormsale_ref`,
   `_stormsale_ref_at` and `_stormsale_ref_source` (session first, cookie as fallback).
3. `woocommerce_payment_complete` dispatches a conversion: a deterministic
   `conversion_id` (HMAC of campaign + order id + order key), `queued` status, then an
   async Action Scheduler job (or an inline send when Action Scheduler is unavailable).
4. `StormSale_Client` encrypts the payload with AES-256-GCM (key:
   `HKDF-SHA256(api_key, "stormsale.payload.v1")`, AAD: `campaign:<id>`), signs the
   envelope with `HKDF(api_key, "stormsale.mac.v1")` and POSTs it with the campaign
   header and the stable conversion id used as the idempotency key.
5. The gateway replies `{status, tx, ledger}`. Verified conversions store the Stellar
   transaction hash on the order; pending ones are polled with backoff; transport and
   5xx/429 failures retry up to five attempts, client errors fail fast.

## Order meta

| Key | Meaning |
| --- | --- |
| `_stormsale_ref` | Referral code |
| `_stormsale_ref_at` | ISO-8601 attribution time |
| `_stormsale_ref_source` | `session` or `cookie` |
| `_stormsale_conversion_id` | Deterministic idempotency key |
| `_stormsale_conversion_status` | `queued`, `pending`, `verified`, `failed` |
| `_stormsale_conversion_tx` | Stellar transaction hash |
| `_stormsale_conversion_error` | Last error, if any |
| `_stormsale_conversion_attempts` | Submission attempts (max 5) |
| `_stormsale_conversion_completed_at` | ISO-8601 verification time |

## Filters

| Filter | Purpose |
| --- | --- |
| `stormsale_gateway_url` | Override the gateway endpoint |
| `stormsale_conversion_payload` | Amend the payload before encryption |
| `stormsale_use_action_scheduler` | Force inline or queued submissions |
| `stormsale_sanitized_settings` | Amend sanitized settings on save |

## Development

The suite runs without WordPress: the tests bootstrap lightweight stubs for the
hooks, options, WooCommerce session, order API and Action Scheduler.

```bash
php tests/run.php                     # PHP unit suite (needs the openssl extension)
node --check assets/js/stormsale-referral.js
node --test tests/js/referral.test.js # JS cookie-lifecycle suite
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

CI runs the same commands on PHP 7.4 and 8.3 (see the `plugin` job in
`.github/workflows/ci.yml`).
