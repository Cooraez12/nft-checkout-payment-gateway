<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// config.php

// NFT-04: Always talk to the payment API over HTTPS.
// The store's own scheme (is_ssl()) must never decide the protocol: an HTTP-only store
// would otherwise send API keys and customer data in plain text. Do NOT change to is_ssl().
if (!defined('NFT_CHECKOUT_PROTOCOL')) {
    define('NFT_CHECKOUT_PROTOCOL', 'https://');
}

// Available environment hosts
if (!defined('NFT_CHECKOUT_PROD_HOST')) {
    define('NFT_CHECKOUT_PROD_HOST', 'rt.app');
}

if (!defined('NFT_CHECKOUT_QA_HOST')) {
    define('NFT_CHECKOUT_QA_HOST', 'qa.rt.app');
}

/**
 * Resolve the API host.
 *
 * - NFT_CHECKOUT_HOST defined in wp-config.php always wins (e.g. a staging site).
 * - Otherwise the WordPress environment type decides: development / staging / local
 *   sites use the QA host, everything else (production) uses the live host.
 */
if (!defined('NFT_CHECKOUT_HOST')) {
    $nft_checkout_environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
    $nft_checkout_is_qa       = in_array($nft_checkout_environment, ['development', 'staging', 'local'], true);
    define('NFT_CHECKOUT_HOST', $nft_checkout_is_qa ? NFT_CHECKOUT_QA_HOST : NFT_CHECKOUT_PROD_HOST);
}

if (!defined('NFT_CHECKOUT_BASE_URL')) {
    // Accept an overridden host with or without a scheme / trailing slash, but always force HTTPS.
    $nft_checkout_parsed_host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', NFT_CHECKOUT_HOST);
    $nft_checkout_parsed_host = rtrim($nft_checkout_parsed_host, '/');
    define('NFT_CHECKOUT_BASE_URL', NFT_CHECKOUT_PROTOCOL . $nft_checkout_parsed_host);
}

if (!defined('NFT_CHECKOUT_API_BASE_URL')) {
    define('NFT_CHECKOUT_API_BASE_URL', NFT_CHECKOUT_BASE_URL . '/payment-api');
}

if (!defined('NFT_CHECKOUT_PLUGIN_VERSION')) {
    define('NFT_CHECKOUT_PLUGIN_VERSION', '1.0.1');
}
