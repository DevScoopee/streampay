=== StormSale Affiliate for WooCommerce ===
Contributors: streampay
Tags: woocommerce, affiliate, referrals, stormsale, stellar
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tracks affiliate referral clicks and logs encrypted, ledger-verified purchases to your StormSale smart campaign.

== Description ==

StormSale Affiliate for WooCommerce is a lightweight bridge between your store and a
StormSale smart campaign on the Stellar ledger.

* **Referral click tracking** – a `?ss_ref=` (or `?ref=`) click sets a 30-day,
  HttpOnly, SameSite=Lax referral cookie that is kept alive across the whole cart and
  checkout lifecycle, mirrored into the WooCommerce session, and finally written to the
  order as attribution meta.
* **Encrypted conversions** – when WooCommerce fires `woocommerce_payment_complete`,
  the order is serialised into a conversion payload, encrypted with AES-256-GCM using a
  key derived from your StormSale API key, and transmitted to your merchant gateway with
  an HMAC-SHA256 signature and a stable idempotency key.
* **Verified on the ledger** – the gateway answers with the Stellar transaction hash;
  the plugin records the conversion status, the transaction hash and the attempt count
  on the order, and re-polls pending confirmations through Action Scheduler until the
  conversion is verified.

Settings live under WooCommerce → StormSale and only ask for two values:

1. **Campaign ID** – the StormSale smart campaign conversions are attributed to.
2. **API Key** – used to encrypt and sign every payload sent to the gateway.

The gateway endpoint defaults to `https://api.stormsale.io/v1/conversions` and can be
overridden with the `stormsale_gateway_url` filter.

== Installation ==

1. Upload the `stormsale-affiliate` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Open WooCommerce → StormSale and enter your Campaign ID and API Key.
4. Share links that contain `?ss_ref=your-affiliate-code`.

== Frequently Asked Questions ==

= What exactly is sent to the gateway? =

An encrypted envelope. The plaintext payload contains the order id, number, status,
currency, totals (string and minor units), item count, creation date, payment method,
the referral code and its source, a SHA-256 hash of the billing email (never the
address itself), the store URL, the plugin version, an ISO-8601 timestamp, a random
nonce and the stable conversion id. Customer names, addresses and line items are never
transmitted.

= What happens if the gateway is down? =

Transport errors and 5xx/429 responses are retried with exponential backoff through
Action Scheduler, up to five attempts per order. Client errors such as an invalid API
key fail fast and are recorded on the order instead.

= Where can I see the result? =

The order edit screen shows a "StormSale conversion" panel with the campaign, the
referral code, the conversion status, the attempt count and the Stellar transaction
hash.

== Changelog ==

= 1.0.0 =
* Initial release: referral click tracking, encrypted order payloads, verified
  Stellar-ledger conversions and the WooCommerce settings page.

== Upgrade Notice ==

= 1.0.0 =
First release.
