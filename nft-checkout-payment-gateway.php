<?php

/**
 * Plugin Name: RT NFT Checkout
 * Description: Accept card payments in WooCommerce through RT's hosted checkout: the customer receives an e-mail voucher and pays on RT's secure page.
 * Author: RT.app
 * Author URI: https://rt.app/
 * Text Domain: rt-nft-checkout
 * Plugin URI: https://rt.app/rt-nft-checkout/
 * Version: 1.0.6
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 6.5.4
 * WC tested up to: 11.3
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Copyright (c) 2024 RT.app
 */

if (!defined('ABSPATH')) {
	exit;
}

define('RTNFCH_GATEWAY_MIN_PHP_VER', '8.0');
define('RTNFCH_GATEWAY_MIN_WC_VER', '6.5.4');
define('RTNFCH_GATEWAY_FILE', __FILE__);
define('RTNFCH_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));

if (!defined('RTNFCH_PAYMENT_ROW_CACHE_GROUP')) {
	define('RTNFCH_PAYMENT_ROW_CACHE_GROUP', 'rtnfch_payment_gateway');
}

// Configuration and utility functions
require_once RTNFCH_GATEWAY_PLUGIN_DIR . 'includes/config.php';
require_once RTNFCH_GATEWAY_PLUGIN_DIR . 'includes/rt-nft-checkout-payment-gateway-utils.php';

// Migrations functions
include_once plugin_dir_path(__FILE__) . 'migration.php';

// Classes that do not depend on WooCommerce being loaded. The gateway and
// block-checkout classes extend WooCommerce classes and are loaded later, on
// plugins_loaded, only when WooCommerce is active.
require_once RTNFCH_GATEWAY_PLUGIN_DIR . 'includes/class-nft-checkout-payment-logger.php';
require_once RTNFCH_GATEWAY_PLUGIN_DIR . 'includes/class-nft-checkout-payment-gateway-admin-notices.php';
require_once RTNFCH_GATEWAY_PLUGIN_DIR . 'includes/class-nft-checkout-payment-state-engine.php';
require_once RTNFCH_GATEWAY_PLUGIN_DIR . 'includes/class-nft-checkout-payment-gateway-rest-api.php';
require_once RTNFCH_GATEWAY_PLUGIN_DIR . 'includes/class-nft-checkout-payment-gateway-loader.php';

// Declare HPOS and block checkout compatibility (NFT-18)
add_action('before_woocommerce_init', function () {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
	}
});

RTNFCH_GATEWAY_Loader::get_instance();

// Tables and option migrations on plugin update (after WooCommerce's own init).
add_action('init', 'rtnfch_maybe_upgrade', 20);

/*
 * NFT-09: WooCommerce's own unpaid-order cancellation is left alone. The
 * 'woocommerce_cancel_unpaid_order' FILTER used to be hooked here with
 * add_action; its callback returned nothing, which told WooCommerce not to
 * cancel ANY unpaid order on the whole store. A cancelled or failed NFT
 * Checkout order tells RT through the status hooks below instead.
 */
add_action('woocommerce_order_status_cancelled', 'rtnfch_cancel_unpaid_order_action', 10, 1);
add_action('woocommerce_order_status_failed', 'rtnfch_cancel_unpaid_order_action', 10, 1);

/**
 * NFT-12: the order-received URL is decided from what the order already
 * records; nothing is fetched from RT while a URL is built. (The order is
 * confirmed with RT once, on the thank-you page itself.)
 */
add_filter('woocommerce_get_checkout_order_received_url', function($url, $order) {

	if (!$order || !is_a($order, 'WC_Order')) {
		return $url;
	}

	if (!rtnfch_is_gateway_order($order)) {
		return $url;
	}

	$wc_status    = $order->get_status();
	$engine_state = $order->get_meta('_rtnfch_state');
	$success_meta = $order->get_meta('_rtnfch_payment_success');

	$is_voucher_dispatched = ($wc_status === 'pending' || $engine_state === 'pending') && (
		!empty($order->get_meta('_rtnfch_voucher_emailed_at')) ||
		!empty($order->get_meta('_rtnfch_voucher_id')) ||
		!empty($order->get_meta('_rtnfch_voucher_reference'))
	);

	$is_valid = in_array($wc_status, ['processing', 'completed', 'on-hold'], true)
		|| $success_meta === 'yes'
		|| $engine_state === 'success'
		|| $is_voucher_dispatched;

	if (!$is_valid) {
		return wc_get_checkout_url();
	}

	return $url;

}, 10, 2);

/**
 * Tell RT that an NFT Checkout order was cancelled or failed in WooCommerce so
 * the payment link behind the voucher is closed.
 *
 * Signed with the key pair of the account that raised the order's voucher
 * (NFT-05, NFT-08). An order that names no configured account is skipped.
 *
 * @param int $order_id The ID of the order.
 */
function rtnfch_cancel_unpaid_order_action($order_id)
{
	if (empty($order_id) || !is_numeric($order_id) || $order_id <= 0) {
		return;
	}

	$order = wc_get_order($order_id);
	if (!$order instanceof WC_Order || !rtnfch_is_gateway_order($order)) {
		return;
	}

	$current_status = $order->get_status();
	if (!in_array($current_status, ['cancelled', 'failed'], true)) {
		return;
	}

	$target_api_status = ($current_status === 'failed') ? 'failed' : 'canceled';

	$uuid = '';
	if (class_exists('RTNFCH_GATEWAY_Loader')) {
		$known = RTNFCH_GATEWAY_Loader::get_instance()->rtnfch_known_order_pay_ids($order);
		$uuid  = (string) ($known[0] ?? '');
	}

	/*
	 * The payment id is known only once the customer has opened the voucher
	 * e-mail AND RT has told this shop. Until then the order is known to RT by
	 * its voucher, so the voucher is always named too: RT cancels the voucher
	 * (the e-mail's button stops working) and whichever payment link it is on.
	 */
	$voucher_id        = sanitize_text_field((string) $order->get_meta('_rtnfch_voucher_id'));
	$voucher_reference = sanitize_text_field((string) $order->get_meta('_rtnfch_voucher_reference'));
	if ($uuid === '') {
		$uuid = $voucher_id;
	}
	if ($uuid === '') {
		$uuid = $voucher_reference;
	}
	if ($uuid === '') {
		// No voucher, no link: nothing to close at RT.
		return;
	}

	// Prevent duplicate API calls for the same order and status.
	$sync_signature = $uuid . ':' . $target_api_status;
	if ($order->get_meta('_rtnfch_cancelled_link_uuid') === $sync_signature) {
		return;
	}

	$account = rtnfch_get_order_account($order);
	if (!is_array($account) || $account['secret_key'] === '') {
		RTNFCH_Logger::warning("Cancel skipped: no configured account matches order #{$order_id}", [
			'order_id' => $order_id,
		]);
		return;
	}

	$request_payload = [
		'order_id'   => (int) $order_id,
		'order_uuid' => $uuid,
		'status'     => $target_api_status,
		'is_sandbox' => (int) $account['is_sandbox'],
	];
	if ($voucher_id !== '') {
		$request_payload['voucher_id'] = $voucher_id;
	}
	if ($voucher_reference !== '') {
		$request_payload['voucher_reference'] = $voucher_reference;
	}
	$json_body = wp_json_encode($request_payload);

	$response = wp_remote_post(
		esc_url_raw(RTNFCH_API_BASE_URL . '/cancel-order-link'),
		[
			'method'    => 'POST',
			'timeout'   => 15,
			'body'      => $json_body,
			'headers'   => rtnfch_signed_headers($account['public_key'], $account['secret_key'], $json_body),
			'sslverify' => true,
		]
	);

	if (is_wp_error($response)) {
		RTNFCH_Logger::error("Cancel/fail API call failed. Order ID: {$order_id}", [
			'order_id' => $order_id,
			'status'   => $target_api_status,
			'error'    => $response->get_error_message(),
		]);
		return;
	}

	$response_code    = (int) wp_remote_retrieve_response_code($response);
	$decoded_response = json_decode(wp_remote_retrieve_body($response), true);

	$api_ok         = $response_code >= 200 && $response_code < 300 && is_array($decoded_response);
	$updated_status = $api_ok ? (string) ($decoded_response['updated_status'] ?? '') : '';

	if ($api_ok && !empty($decoded_response['status'])) {
		$order->update_meta_data('_rtnfch_cancelled_link_uuid', $sync_signature);
		if ($target_api_status === 'canceled' && !$order->get_meta('_rtnfch_rt_cancel_noted')) {
			$order->update_meta_data('_rtnfch_rt_cancel_noted', 'yes');
			$order->add_order_note(
				'<strong>NFT Checkout</strong><br><br>' .
				esc_html__('The order was cancelled, so its card payment link was cancelled in your RT account too. The customer can no longer pay with the e-mail RT sent them.', 'rt-nft-checkout')
			);
		}
		$order->save();
	} elseif ($api_ok && $target_api_status === 'canceled' && $updated_status === 'reconciliation_required') {
		// RT refused: the customer's card payment is already going through (or
		// went through). The order sync will bring the result back here.
		if (!$order->get_meta('_rtnfch_rt_cancel_refused_noted')) {
			$order->update_meta_data('_rtnfch_rt_cancel_refused_noted', 'yes');
			$order->add_order_note(
				'<strong>NFT Checkout</strong><br><br>' .
				esc_html__('This order was cancelled while the customer\'s card payment was already going through, so the payment could not be stopped. This order will be updated when RT reports the result; if the payment succeeds, refund it in your RT account.', 'rt-nft-checkout')
			);
			$order->save();
		}
	}

	RTNFCH_Logger::info("Cancel/fail API response received for Order ID: {$order_id}.", [
		'order_id'      => $order_id,
		'status'        => $target_api_status,
		'response_code' => $response_code,
		'api_status'    => $decoded_response['status'] ?? null,
		'updated'       => $updated_status,
		'api_message'   => is_array($decoded_response) ? sanitize_text_field((string) ($decoded_response['message'] ?? '')) : '',
	]);
}

/*
 * Test mode is shown to the merchant, but only on this plugin's settings
 * screen and WooCommerce order screens, never across the whole dashboard.
 */
add_action('admin_notices', function () {
	if (!current_user_can('manage_woocommerce') || !rtnfch_is_sandbox() || !rtnfch_is_own_admin_screen(false)) {
		return;
	}
	$settings = rtnfch_get_settings();
	if (($settings['enabled'] ?? 'no') !== 'yes') {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>' .
		esc_html__('RT NFT Checkout: test mode is on.', 'rt-nft-checkout') . '</strong> ' .
		esc_html__('Card orders are test orders and no real money is received. Turn test mode off in the RT NFT Checkout settings before taking real orders.', 'rt-nft-checkout') .
		'</p></div>';
});
