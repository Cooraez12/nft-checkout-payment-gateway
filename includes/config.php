<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// config.php

// NFT-04: Always talk to the payment API over HTTPS.
// The store's own scheme (is_ssl()) must never decide the protocol: an HTTP-only store
// would otherwise send API keys and customer data in plain text. Do NOT change to is_ssl().
if (!defined('NFT_CHECKOUT_PROTOCOL')) {
    define('NFT_CHECKOUT_PROTOCOL', 'https://');
}

/*
 * NFT-04: a store talks to the production service unless its wp-config.php
 * says otherwise. Two overrides, in order of precedence:
 *
 *   define('NFT_CHECKOUT_HOST', 'qa.rt.app');          // an explicit host always wins
 *   define('NFT_CHECKOUT_ENVIRONMENT', 'qa');          // 'dev' | 'qa' | 'production'
 *
 * Nothing else - not WP_ENVIRONMENT_TYPE, not a default in this file - may
 * point a store at a non-production host: a merchant's live keys and their
 * customers' data must never reach qa.rt.app by accident.
 */
if (!defined('NFT_CHECKOUT_DEV_HOST')) {
    define('NFT_CHECKOUT_DEV_HOST', 'dev.rt.app');
}

if (!defined('NFT_CHECKOUT_QA_HOST')) {
    define('NFT_CHECKOUT_QA_HOST', 'qa.rt.app');
}

if (!defined('NFT_CHECKOUT_PROD_HOST')) {
    define('NFT_CHECKOUT_PROD_HOST', 'rt.app');
}

if (!defined('NFT_CHECKOUT_HOST')) {
    $nft_checkout_active_env = defined('NFT_CHECKOUT_ENVIRONMENT') ? strtolower((string) NFT_CHECKOUT_ENVIRONMENT) : 'production';

    switch ($nft_checkout_active_env) {
        case 'dev':
        case 'development':
        case 'local':
            $nft_checkout_target_host = NFT_CHECKOUT_DEV_HOST;
            break;
        case 'qa':
        case 'staging':
            $nft_checkout_target_host = NFT_CHECKOUT_QA_HOST;
            break;
        default:
            $nft_checkout_target_host = NFT_CHECKOUT_PROD_HOST;
            break;
    }

    define('NFT_CHECKOUT_HOST', $nft_checkout_target_host);
}

if (!defined('NFT_CHECKOUT_BASE_URL')) {
    // Accept an overridden host with or without a scheme / trailing slash, but always force HTTPS.
    $nft_checkout_parsed_host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', (string) NFT_CHECKOUT_HOST);
    $nft_checkout_parsed_host = rtrim($nft_checkout_parsed_host, '/');
    define('NFT_CHECKOUT_BASE_URL', NFT_CHECKOUT_PROTOCOL . $nft_checkout_parsed_host);
}

if (!defined('NFT_CHECKOUT_API_BASE_URL')) {
    define('NFT_CHECKOUT_API_BASE_URL', NFT_CHECKOUT_BASE_URL . '/payment-api');
}

if (!defined('NFT_CHECKOUT_PLUGIN_VERSION')) {
    define('NFT_CHECKOUT_PLUGIN_VERSION', '1.0.2');
}

/* Gateway id. Orders placed before 1.0.2 may carry the underscore spelling. */
if (!defined('NFT_CHECKOUT_GATEWAY_ID')) {
    define('NFT_CHECKOUT_GATEWAY_ID', 'nft-checkout');
}

/* REST namespace of the return / webhook endpoint. */
if (!defined('NFT_CHECKOUT_REST_NAMESPACE')) {
    define('NFT_CHECKOUT_REST_NAMESPACE', 'nft-checkout/v1');
}

/* NFT-13: this plugin's own payment-link table, never shared with another gateway. */
if (!defined('NFT_CHECKOUT_TABLE')) {
    define('NFT_CHECKOUT_TABLE', 'rt_nft_checkout_payment_link');
}

/* NFT-13: every name registered with WordPress carries a plugin-specific prefix. */
if (!defined('NFT_CHECKOUT_CRON_HOOK')) {
    define('NFT_CHECKOUT_CRON_HOOK', 'rt_nft_checkout_cron_event');
}
if (!defined('NFT_CHECKOUT_CRON_INTERVAL')) {
    define('NFT_CHECKOUT_CRON_INTERVAL', 'rt_nft_checkout_every_two_hours');
}
if (!defined('NFT_CHECKOUT_ASYNC_SYNC_HOOK')) {
    define('NFT_CHECKOUT_ASYNC_SYNC_HOOK', 'rt_nft_checkout_async_sync_order');
}

/* Option names. Only this plugin's own options are ever read (NFT-13). */
if (!defined('NFT_CHECKOUT_SETTINGS_OPTION')) {
    define('NFT_CHECKOUT_SETTINGS_OPTION', 'woocommerce_nft-checkout_settings');
}
if (!defined('NFT_CHECKOUT_ACCOUNTS_OPTION')) {
    define('NFT_CHECKOUT_ACCOUNTS_OPTION', 'woocommerce_nft-checkout_payment_gateway_accounts');
}
