=== NFT Checkout ===
Contributors: rtapp
Tags: woocommerce, payment gateway, card payments, RT.app
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
WC requires at least: 6.5.4
WC tested up to: 11.0
Stable tag: 1.0.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept card payments in WooCommerce through RT's hosted checkout: the customer receives an e-mail voucher and pays on RT's secure page.

== Description ==

NFT Checkout adds a "Pay by card" option to your WooCommerce checkout. When the customer places the order, RT e-mails them a secure payment link; they open it and pay by card on RT's hosted page (app.rt.app). The order stays pending until RT confirms the payment, then moves to Processing (or Completed, as configured).

Supports the classic and block checkouts, WooCommerce High-Performance Order Storage, several RT business accounts with priority routing, and a sandbox mode for testing.

You need an RT business account to use this plugin. Copy your API keys from your RT dashboard under Developers > API keys.

== External Services ==

This plugin connects to RT's payment service, operated by RT.app at https://app.rt.app, to send payment vouchers, check payment status and check that your RT business account is active. Nothing is sent until you have saved your API keys in the plugin settings.

What is sent, and when:

* `https://app.rt.app/payment-api/voucher/send` - when a customer places an order with this gateway. Sends the order number, order total and currency, the ordered items (names, quantities, line totals and product image URLs), the customer's name, e-mail address, phone number and billing address, the customer's IP address, the WooCommerce customer ID (0 for guests), the return URL of your shop, your account's public API key and the plugin version. The request is signed with your secret API key; the secret itself is not sent.
* `https://app.rt.app/payment-api/dailylimit` and `https://app.rt.app/payment-api/check-merchant-status` - when the checkout page is shown and when an order is placed, to check that your account is active and the order total is within its daily limit. Sends the amount and your public API key, signed with the secret key.
* `https://app.rt.app/payment-api/update-txn-status` - when the customer returns from RT's payment page or opens the order-received page, when you run "Check NFT Checkout payment status" on an order, in the background when you open a pending order in the admin, and every two hours for orders still awaiting payment. Sends the order number, the payment ID (when known) and your public API key, signed with the secret key.
* `https://app.rt.app/payment-api/cancel-order-link` - when an unpaid order is cancelled or fails in WooCommerce. Sends the order number and payment ID, signed with the secret key.
* `https://app.rt.app/payment-api/sync-account-status` - every two hours and when you save the settings or press "Check with RT", to check that your API keys are valid. Sends each account's public key with a signature made with its secret key.
* `https://app.rt.app/payment-api/plugin/check/plugin` - only if you enable "Share plugin status with RT" in the settings (off by default): when the plugin is activated, deactivated, its settings are saved, and after each account sync. Sends your account titles and public keys, your shop's domain name, and the plugin, WordPress and WooCommerce versions.

RT sends payment status updates back to your shop at `/wp-json/nft-checkout/v1/data`, signed with your secret API key. Updates without a valid signature are rejected.

Your secret API keys are stored in your WordPress database and are only ever used to sign requests; they are never transmitted to RT or shown in the browser. Card details are entered on RT's payment page and never pass through your shop.

* Terms of Service: https://rt.app/terms
* Privacy Policy: https://rt.app/privacy

== Installation ==

1. Download the plugin ZIP file.
2. In WordPress go to Plugins > Add New > Upload Plugin and upload the ZIP, or extract it into `wp-content/plugins`.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Go to WooCommerce > Settings > Payments > NFT Checkout, turn on the payment method and add an account with your RT keys (sandbox keys are enough to test).
5. Press "Check with RT" to confirm RT accepts the keys.

== Frequently Asked Questions ==

= How do I obtain API keys? =

Sign in to your RT dashboard and open Developers > API keys. Sandbox (test) keys and live keys are both there.

= Why is my order "Pending payment"? =

The customer has been e-mailed their payment link but has not paid yet. The order moves to Processing (or Completed, as configured) when RT confirms the payment.

= Can I test without taking real payments? =

Yes. Turn on sandbox mode and enter the sandbox keys from your RT dashboard. No real money moves, and test orders are labelled on the order screen. Turn sandbox mode off before taking real orders.

== Changelog ==

= 1.0.3 =
* The production address is https://app.rt.app/payment-api again (1.0.2's last change pointed it at the website host).
* Under the card minimum, "Pay by card" stays listed and says "Card payments start at $5.00. Add more to your order to pay by card."; Place Order is refused with the same sentence instead of the option disappearing.
* Plainer readme.

= 1.0.2 =
* Security: the customer return link can no longer change an order's status; the order is confirmed with RT instead.
* Security: webhooks now require a valid HMAC signature made with the secret key of the account that raised the order; a public key, secret key, nonce or bearer token is no longer accepted as a credential, and replayed deliveries are ignored.
* Security: an order is marked paid only when RT's answer names this order, the exact amount, the currency and the payment ID; the unauthenticated status lookups were removed.
* Security: the plugin now talks to the production service by default; `NFT_CHECKOUT_HOST` in `wp-config.php` overrides it.
* Security: your secret key is no longer sent to RT in any request body; every request is signed instead.
* A paid order can no longer be reset to pending or receive a new voucher, and is never moved down to failed or cancelled automatically.
* Removed the hook that stopped WooCommerce auto-cancelling unpaid orders for every payment method.
* The checkout script no longer rewrites billing names or city.
* Block checkout now goes through WooCommerce's Store API, so all WooCommerce validation and extensions run.
* Fixed the pay-for-order redirect; building the order-received URL no longer calls RT.
* Removed the import of Unified / Voucher Pay plugin credentials; the plugin uses its own database table and `rt_`-prefixed cron and transient names.
* Rate limits are counted per session and per order instead of per shared IP.
* Admin order screens no longer call RT while rendering; status checks run in the background.
* Secret keys are masked in the settings screen; API responses are no longer logged in full.
* Plugin status is reported to RT only with your consent (new setting, off by default).
* Corrected branding, plugin headers, block compatibility declaration and uninstall cleanup.

= 1.0.1 =
* Security: the account status sync no longer sends secret keys to the API; accounts are identified by public key and the request is HMAC-signed.
* Security: all API requests are always sent over HTTPS, regardless of the store's own protocol.
* Documentation and Support links now point at the production site.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.3 =
Points the plugin back at https://app.rt.app/payment-api and explains the card minimum at checkout. No settings change.

= 1.0.2 =
Security release. Requires the RT payment service update that accepts signed requests (deployed by RT). After updating, open the gateway settings once and press "Check with RT". Orders placed before the update keep working.

= 1.0.1 =
Security release: signed account sync and HTTPS-only API traffic. All stores should update.

= 1.0.0 =
Initial release.

== Support ==

For support, visit: https://rt.app/contact-us
