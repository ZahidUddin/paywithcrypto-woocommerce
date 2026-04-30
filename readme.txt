=== PayWithCrypto for WooCommerce ===
Contributors: zahiduddin
Tags: woocommerce, crypto, cryptocurrency, payment gateway, wallet
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept PayWithCrypto / PWC crypto wallet transfer payments in WooCommerce.

== Description ==

PayWithCrypto for WooCommerce adds a WooCommerce payment gateway integration for the PayWithCrypto / PWC service using Method 2: Crypto Wallet Transfer.

This plugin is maintained by Zahid Uddin. It is an integration for PayWithCrypto / PWC and does not claim official ownership, partnership, endorsement, or affiliation unless separately authorized by the service provider.

Customers can place an order and then see wallet transfer details including the exact crypto amount, chain, network, wallet address, QR code, copy buttons, and live payment status polling.

The API Secret and signing logic remain server-side. The frontend receives only non-sensitive payment details.

== Installation ==

1. Upload the `paywithcrypto-woocommerce` folder to `/wp-content/plugins/`.
2. Activate `PayWithCrypto for WooCommerce` in WordPress admin.
3. Go to WooCommerce > Settings > Payments > PayWithCrypto.
4. Enable the gateway.
5. Enter the App Key / API Key.
6. Enter the PWC Secret in the Secret field.
7. Choose environment, chain, network, and crypto token.
8. Save changes.

== Frequently Asked Questions ==

= Does the return URL mark orders paid? =

No. The return URL only displays the latest server-confirmed payment status. Orders are completed only after server-side verification through the PWC status API or a verified webhook/callback.

= Is the API Secret shown after saving? =

No. The Secret field is rendered as an empty password field after saving. Leaving it blank keeps the stored secret.

= Which wallets can customers use? =

Customers can use wallet-compatible flows such as MetaMask, Trust Wallet, Coinbase Wallet, TokenPocket, imToken, and ERC-20/BEP-20 compatible wallets, depending on the selected chain, network, and token.

= What happens to partial payments? =

PARTIALLY_PAID payments are placed on hold and do not complete the WooCommerce order.

== External Services ==

This plugin connects to PayWithCrypto / PWC to create and confirm crypto payment orders.

When a customer chooses Pay with Crypto, the plugin sends payment/order data to PWC, including:
* WooCommerce order/external ID
* Order amount
* Fiat currency
* Selected chain, network, and crypto token
* Callback/notify URL
* Return URL
* Product line item names, SKUs, quantities, and unit prices, if sent by the plugin

The plugin may also query PWC from the store server to retrieve payment status, wallet address, QR code data, crypto amount, confirmed amount, remaining amount, transaction hash, transaction records, and payment status.

Service provider:
PayWithCrypto / PWC

Service URL:
https://paywithcrypto.vip

API URL:
https://checkout.paywithcrypto.vip/order

Terms:
https://zahiduddin.com/

Privacy Policy:
https://zahiduddin.com/

Important payment safety note:
The return URL does not mark WooCommerce orders paid by itself. Orders are completed only after server-side verification through the PWC status API or a verified webhook/callback.

== Screenshots ==

No screenshots are included in this release.

== Changelog ==

= 1.0.0 =
* Initial release.
