=== PayWithCrypto for WooCommerce ===
Contributors: paywithcrypto
Tags: woocommerce, crypto, payment gateway, wallet, usdt
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept PayWithCrypto Crypto Wallet Transfer payments in WooCommerce.

== Description ==

PayWithCrypto for WooCommerce adds a custom WooCommerce payment gateway for PayWithCrypto Crypto Wallet Transfer.
This is the basic/free Crypto Wallet Transfer edition.

Customers place an order, then receive a payment page with:

* Exact crypto amount
* Chain and network
* Wallet address
* Local QR code rendering
* Copy buttons
* Live payment status polling

The API Secret and signing logic remain server-side. The frontend receives only non-sensitive payment details.

== Installation ==

1. Copy the `paywithcrypto-woocommerce` folder into `wp-content/plugins/`.
2. Activate `PayWithCrypto for WooCommerce` in WordPress admin.
3. Go to WooCommerce > Settings > Payments > PayWithCrypto.
4. Enable the gateway.
5. Enter the App Key key_id only, for example `ak_live_xxx`.
6. Enter the PWC Secret in the Secret field.
7. Choose environment, chain, network, and token. Fiat currency is read automatically from the WooCommerce order currency.
8. Save changes.

== Configuration ==

Gateway settings are located at:

WooCommerce > Settings > Payments > PayWithCrypto

Required fields:

* App Key / API Key: key_id only. Do not paste a combined `ak_xxx.secret` credential.
* Secret: stored in WooCommerce settings and never rendered back into the admin field.
* Environment: use Production (`https://checkout.paywithcrypto.vip/order`) with live credentials, or Sandbox/Test (`https://api-test.paywithcrypto.vip/order`) with sandbox credentials.
* Fiat Currency: sent automatically from the WooCommerce order currency, for example `USD`.
* Chain: choose `BSC` for BNB Smart Chain or `ETH` for Ethereum. The selected chain must match the network and token accepted by your PWC account.
* Network: choose `mainnet` for live payments, `test` for the documented BSC sandbox example, or `sepolia` for the documented Ethereum sandbox example.
* Crypto Token: choose `USDT-ERC20` for live USDT payments, `PWCUSD-ERC20` for the documented BSC sandbox token, or `TEST-ERC20` for the documented Ethereum Sepolia test token.
* Data Cleanup: optional uninstall cleanup for plugin settings and diagnostic options.

Signing uses the current PWC documentation behavior: append `SecretHash = SHA256(secret)` to header and body signature strings. The required `App-Version` header is sent automatically from the plugin version.

== Notify URL and Return URL ==

The plugin sends this WooCommerce API callback URL to PWC as `callback_url`:

`/wc-api/paywithcrypto_notify`

A REST alias is also available:

`/wp-json/paywithcrypto/v1/notify`

Return URLs are generated per WooCommerce order and include the order key. The return page always queries PWC server-side before displaying final status.

== Payment Flow ==

1. Customer selects Pay with Crypto at WooCommerce checkout.
2. WooCommerce creates the order and marks it pending payment.
3. The plugin creates a PWC order through `POST /api/v1/orders`.
4. The customer is redirected to the plugin payment page.
5. The page polls `/wp-json/paywithcrypto/v1/status/{order_id}?key={order_key}` every 3.5 seconds.
6. The backend queries PWC `GET /api/v1/payment/{payment_id}` and returns safe payment fields.
7. Webhooks and server-side polling update the WooCommerce order status.

== Testing Checklist ==

* Gateway disabled: payment option is hidden.
* Gateway enabled with credentials: payment option is visible.
* Missing credentials: payment option is unavailable and admin warning appears.
* Successful PWC create order: PWC payment ID is saved in order meta.
* API failure: checkout shows a WooCommerce notice and no fatal error occurs.
* Payment page loads QR/address/amount when PWC status returns those fields.
* Copy wallet address and amount buttons work on desktop and mobile.
* Polling updates status and stops for terminal statuses.
* Webhook `PAID` updates WooCommerce through `payment_complete()`.
* Webhook `FAILED` updates WooCommerce order to failed.
* Webhook `PENDING` does not complete the order.
* Duplicate `PAID` webhook does not duplicate payment completion.
* Return URL shows server-confirmed status.
* Secret is not visible in frontend HTML, JavaScript, logs, or REST responses.
* Mobile payment page remains usable.
* Partial/wrong-token `payment_status_message` displays to the customer.

== Security Notes ==

* The API Secret is never sent to frontend JavaScript, browser HTML, request headers to the browser, or logs.
* Webhook signatures are validated when provided. Unsigned webhooks are confirmed by querying PWC server-side before updating the order.
* The public polling endpoint requires the WooCommerce order key.
* Debug logs redact sensitive values and do not log signatures.

== Changelog ==

= 1.0.1 =
* Basic-edition hardening release.
* Added optional uninstall cleanup setting.
* Added extension hooks for future pro add-ons (`pwc_plugin_loaded`, `pwc_gateway_classes`).

= 1.0.0 =
* Initial Crypto Wallet Transfer gateway implementation.
