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
if (!defined('NFT_CHECKOUT_DEV_HOST')) {
    define('NFT_CHECKOUT_DEV_HOST', 'dev.rt.app');
}

if (!defined('NFT_CHECKOUT_QA_HOST')) {
    define('NFT_CHECKOUT_QA_HOST', 'qa.rt.app');
}

if (!defined('NFT_CHECKOUT_PROD_HOST')) {
    define('NFT_CHECKOUT_PROD_HOST', 'rt.app');
}

/**
 * Choose active environment:
 * 'dev'        => dev.rt.app (Development)
 * 'qa'         => qa.rt.app  (QA)
 * 'production' => rt.app     (Live)
 */
if (!defined('NFT_CHECKOUT_ENVIRONMENT')) {
    define('NFT_CHECKOUT_ENVIRONMENT', 'qa');
}

// Resolve target host based on NFT_CHECKOUT_ENVIRONMENT
$nft_checkout_active_env = defined('NFT_CHECKOUT_ENVIRONMENT') ? strtolower(NFT_CHECKOUT_ENVIRONMENT) : '';

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
    case 'prod':
    case 'production':
        $nft_checkout_target_host = NFT_CHECKOUT_PROD_HOST;
        break;
    default:
        if (defined('NFT_CHECKOUT_HOST')) {
            $nft_checkout_target_host = NFT_CHECKOUT_HOST;
        } else {
            $nft_checkout_environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
            $nft_checkout_is_qa       = in_array($nft_checkout_environment, ['development', 'staging', 'local'], true);
            $nft_checkout_target_host = $nft_checkout_is_qa ? NFT_CHECKOUT_QA_HOST : NFT_CHECKOUT_PROD_HOST;
        }
        break;
}

if (!defined('NFT_CHECKOUT_HOST')) {
    define('NFT_CHECKOUT_HOST', $nft_checkout_target_host);
}

if (!defined('NFT_CHECKOUT_BASE_URL')) {
    // Accept an overridden host with or without a scheme / trailing slash, but always force HTTPS.
    $nft_checkout_parsed_host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $nft_checkout_target_host);
    $nft_checkout_parsed_host = rtrim($nft_checkout_parsed_host, '/');
    define('NFT_CHECKOUT_BASE_URL', NFT_CHECKOUT_PROTOCOL . $nft_checkout_parsed_host);
}

if (!defined('NFT_CHECKOUT_API_BASE_URL')) {
    define('NFT_CHECKOUT_API_BASE_URL', NFT_CHECKOUT_BASE_URL . '/payment-api');
}

if (!defined('NFT_CHECKOUT_PLUGIN_VERSION')) {
    define('NFT_CHECKOUT_PLUGIN_VERSION', '1.0.1');
}
