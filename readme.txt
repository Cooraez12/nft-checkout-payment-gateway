=== RT NFT Checkout ===
Contributors: rtapp
Tags: woocommerce, payment gateway, card payments, credit card, checkout
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
WC requires at least: 6.5.4
WC tested up to: 11.3
Stable tag: 1.0.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept card payments in WooCommerce through RT's hosted checkout: the customer receives an e-mail voucher and pays on RT's secure page.

== Description ==

RT NFT Checkout adds a "Pay by card" option to your WooCommerce checkout. When the customer places the order, RT e-mails them a secure payment link; they open it and pay by card on RT's hosted page (app.rt.app). The order stays pending until RT confirms the payment, then moves to Processing (or Completed, as configured).

Supports the classic and block checkouts, WooCommerce High-Performance Order Storage, several RT business accounts with priority routing, and a sandbox mode for testing.

You need an RT business account to use this plugin. Copy your API keys from your RT dashboard under Developers > API keys.

== External Services ==

This plugin connects to RT's payment service, operated by RT.app at https://app.rt.app, to send payment vouchers, check payment status and check that your RT business account is active. Nothing is sent until you have saved your API keys in the plugin settings.

What is sent, and when:

* `https://app.rt.app/payment-api/voucher/send` - when a customer places an order with this gateway. Sends the order number, order total and currency, the ordered items (names, quantities, line totals and product image URLs), the customer's name, e-mail address, phone number and billing address, the customer's IP address, the WooCommerce customer ID (0 for guests), the return URL of your shop, your account's public API key and the plugin version. The request is signed with your secret API key; the secret itself is not sent.
* `https://app.rt.app/payment-api/dailylimit` and `https://app.rt.app/payment-api/check-merchant-status` - when the checkout page is shown and when an order is placed, to check that your account is active and the order total is within its daily limit. Sends the amount and your public API key, signed with the secret key.
* `https://app.rt.app/payment-api/update-txn-status` - when the customer returns from RT's payment page or opens the order-received page, when you run "Check RT NFT Checkout payment status" on an order, in the background when you open a pending order in the admin, and every two hours for orders still awaiting payment. Sends the order number, the payment ID (when known) and your public API key, signed with the secret key.
* `https://app.rt.app/payment-api/cancel-order-link` - when an unpaid order is cancelled or fails in WooCommerce. Sends the order number and payment ID, signed with the secret key.
* `https://app.rt.app/payment-api/sync-account-status` - every two hours and when you save the settings or press "Check with RT", to check that your API keys are valid. Sends each account's public key with a signature made with its secret key.
* `https://app.rt.app/payment-api/plugin/check/plugin` - only if you enable "Share plugin status with RT" in the settings (off by default): when the plugin is deactivated, when its settings are saved, and after each account sync. Nothing is sent when the plugin is activated. Sends your account titles and public keys, your shop's domain name, and the plugin, WordPress and WooCommerce versions.

RT sends payment status updates back to your shop at `/wp-json/rt-nft-checkout/v1/data`, signed with your secret API key. Updates without a valid signature are rejected.

Your secret API keys are stored in your WordPress database and are only ever used to sign requests; they are never transmitted to RT or shown in the browser. Card details are entered on RT's payment page and never pass through your shop.

* Terms of Service: https://rt.app/terms/
* Privacy Policy: https://rt.app/privacy/
* All RT legal terms: https://rt.app/legal/

== Installation ==

1. In WordPress go to Plugins > Add New, search for "RT NFT Checkout" and press Install Now. (Or upload the plugin ZIP under Plugins > Add New > Upload Plugin.)
2. Activate the plugin. WooCommerce must be active.
3. Go to WooCommerce > Settings > Payments > RT NFT Checkout, turn on the payment method and add an account with your RT keys (sandbox keys are enough to test).
4. Press "Check with RT" to confirm RT accepts the keys.

== Frequently Asked Questions ==

= How do I obtain API keys? =

Sign in to your RT dashboard and open Developers > API keys. Sandbox (test) keys and live keys are both there.

= Why is my order "Pending payment"? =

The customer has been e-mailed their payment link but has not paid yet. The order moves to Processing (or Completed, as configured) when RT confirms the payment.

= Can I test without taking real payments? =

Yes. Turn on sandbox mode and enter the sandbox keys from your RT dashboard. No real money moves, and test orders are labelled on the order screen. Turn sandbox mode off before taking real orders.

== Screenshots ==

1. The "Pay by card" option at checkout.
2. The settings screen with a payment account added.

== Changelog ==

= 1.0.7 =
* Updated the plugin's web address in the plugin details.

= 1.0.6 =
* Cancelling an order in WooCommerce now cancels its card payment in your RT account too: the e-mail RT sent the customer stops working, whether or not they had opened it.
* If the card payment is already going through when you cancel, RT keeps the payment and the order says so.

= 1.0.5 =
* All functions, classes, settings, order data and scripts now use the plugin's own "rtnfch" prefix. Existing settings, orders and payment links are moved over automatically on update.
* Activation now only creates the plugin's table and settings. It no longer contacts RT.
* The payment-link table is created reliably on all MySQL versions.
* Test-mode and setup notices appear only on the plugin's settings screen and WooCommerce order screens.
* Admin styles are limited to the plugin's own settings screen.

= 1.0.4 =
* New name: RT NFT Checkout. Prepared for the WordPress.org plugin directory.
* Licence is now GPLv2 or later.

= 1.0.4 =
* Cancelling an order in WooCommerce now cancels its card payment in your RT account too: the e-mail RT sent the customer stops working, whether or not they had opened it. Needs the RT payment service update of the same date (deployed by RT).
* If the card payment is already going through when you cancel, RT keeps the payment and the order says so.

= 1.0.4 =
Cancelled orders now show as cancelled in RT and their payment e-mail stops working. No settings change.

= 1.0.3 =
* Explains the card minimum at checkout instead of hiding the payment method.

= 1.0.2 =
* Security improvements: signed requests and webhooks, stricter payment checks, and no secret keys sent over the network.
* Works with the block checkout and High-Performance Order Storage.
* Plugin status is shared with RT only if you turn it on (off by default).

= 1.0.1 =
* Security: signed account checks and HTTPS-only connections.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.7 =
No settings change.

= 1.0.6 =
Cancelled orders now show as cancelled in RT and their payment e-mail stops working. No settings change.

= 1.0.5 =
Renames the plugin's stored data to its own prefix. Settings and orders are moved automatically. Test stores that point at another RT environment should use the rtnfch_api_base filter or the RTNFCH_HOST constant.

== Support ==

For help, visit https://rt.app/help/ or e-mail support@rt.app.
