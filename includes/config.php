<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// config.php

/*
 * Where this plugin talks to RT. ONE setting decides it: RTNFCH_API_BASE_URL.
 *
 * Production (the default, for Live AND Sandbox keys) is
 * https://app.rt.app/payment-api. Sandbox mode does not use a different
 * address: the same service answers both, and the keys plus the is_sandbox
 * flag in each request decide the mode.
 *
 * A test shop can point at another RT environment without editing code:
 *
 *   add_filter('rtnfch_api_base', function () { return 'https://<test-environment>.rt.app/payment-api'; });
 *
 * (in a must-use plugin, so it runs before this file), or by defining
 * RTNFCH_API_BASE_URL in wp-config.php. The older wp-config
 * setting RTNFCH_HOST (a bare host) is still honoured.
 * The address is always HTTPS and must be on rt.app, so a shop's keys and its
 * customers' details can never be sent anywhere else by a filter.
 */
if (!defined('RTNFCH_DEFAULT_API_BASE_URL')) {
	define('RTNFCH_DEFAULT_API_BASE_URL', 'https://app.rt.app/payment-api');
}

if (!function_exists('rtnfch_clean_api_base')) {
	/**
	 * A safe API base: https, a host on rt.app, no trailing slash. Anything
	 * else falls back to production.
	 *
	 * @param mixed $candidate
	 * @return string
	 */
	function rtnfch_clean_api_base($candidate)
	{
		$candidate = is_string($candidate) ? trim($candidate) : '';
		if ($candidate === '') {
			return RTNFCH_DEFAULT_API_BASE_URL;
		}
		if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate)) {
			$candidate = 'https://' . $candidate;
		}
		$parts = wp_parse_url($candidate);
		$host  = is_array($parts) && !empty($parts['host']) ? strtolower((string) $parts['host']) : '';
		if ($host === '' || !($host === 'rt.app' || substr($host, -7) === '.rt.app')) {
			return RTNFCH_DEFAULT_API_BASE_URL;
		}
		$path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
		if ($path === '') {
			$path = '/payment-api';
		}
		return 'https://' . $host . (!empty($parts['port']) ? ':' . (int) $parts['port'] : '') . $path;
	}
}

if (!defined('RTNFCH_PROTOCOL')) {
	define('RTNFCH_PROTOCOL', 'https://');
}

if (!defined('RTNFCH_API_BASE_URL')) {
	$rtnfch_api_base = RTNFCH_DEFAULT_API_BASE_URL;

	// Older wp-config setting: an explicit host.
	if (defined('RTNFCH_HOST') && is_string(RTNFCH_HOST) && trim(RTNFCH_HOST) !== '') {
		$rtnfch_api_base = 'https://' . preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', rtrim(trim(RTNFCH_HOST), '/')) . '/payment-api';
	}

	define('RTNFCH_API_BASE_URL', rtnfch_clean_api_base(apply_filters('rtnfch_api_base', $rtnfch_api_base)));
}

/* Derived from the API base; never set separately. Used for links to RT pages
 * (Developers) and to recognise RT's own origin. */
if (!defined('RTNFCH_HOST')) {
	define('RTNFCH_HOST', (string) wp_parse_url(RTNFCH_API_BASE_URL, PHP_URL_HOST));
}
if (!defined('RTNFCH_BASE_URL')) {
	define('RTNFCH_BASE_URL', RTNFCH_PROTOCOL . (string) wp_parse_url(RTNFCH_API_BASE_URL, PHP_URL_HOST));
}

if (!defined('RTNFCH_PLUGIN_VERSION')) {
    define('RTNFCH_PLUGIN_VERSION', '1.0.7');
}

/* Gateway id. Orders placed before 1.0.2 may carry the underscore spelling. */
if (!defined('RTNFCH_GATEWAY_ID')) {
    define('RTNFCH_GATEWAY_ID', 'rt-nft-checkout');
}

/* REST namespace of the return / webhook endpoint. */
if (!defined('RTNFCH_REST_NAMESPACE')) {
    define('RTNFCH_REST_NAMESPACE', 'rt-nft-checkout/v1');
}

/* NFT-13: this plugin's own payment-link table, never shared with another gateway. */
if (!defined('RTNFCH_TABLE')) {
    define('RTNFCH_TABLE', 'rtnfch_payment_link');
}

/* NFT-13: every name registered with WordPress carries a plugin-specific prefix. */
if (!defined('RTNFCH_CRON_HOOK')) {
    define('RTNFCH_CRON_HOOK', 'rtnfch_cron_event');
}
if (!defined('RTNFCH_CRON_INTERVAL')) {
    define('RTNFCH_CRON_INTERVAL', 'rtnfch_every_two_hours');
}
if (!defined('RTNFCH_ASYNC_SYNC_HOOK')) {
    define('RTNFCH_ASYNC_SYNC_HOOK', 'rtnfch_async_sync_order');
}

/* Option names. Only this plugin's own options are ever read (NFT-13). */
if (!defined('RTNFCH_SETTINGS_OPTION')) {
    define('RTNFCH_SETTINGS_OPTION', 'woocommerce_rt-nft-checkout_settings');
}
if (!defined('RTNFCH_ACCOUNTS_OPTION')) {
    define('RTNFCH_ACCOUNTS_OPTION', 'rtnfch_accounts');
}
