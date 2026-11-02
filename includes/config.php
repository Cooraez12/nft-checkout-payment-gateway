<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
 
// config.php
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
    define('NFT_CHECKOUT_ENVIRONMENT', 'dev');
}
 
// Resolve target host
$nft_checkout_active_env = strtolower(NFT_CHECKOUT_ENVIRONMENT);
 
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
            $nft_checkout_is_qa = in_array($nft_checkout_environment, ['development', 'staging', 'local'], true);
            $nft_checkout_target_host = $nft_checkout_is_qa ? NFT_CHECKOUT_QA_HOST : NFT_CHECKOUT_PROD_HOST;
        }
        break;
}
 
if (!defined('NFT_CHECKOUT_HOST')) {
    define('NFT_CHECKOUT_HOST', $nft_checkout_target_host);
}
 
if (!defined('NFT_CHECKOUT_BASE_URL')) {
    $nft_checkout_parsed_host = preg_replace('#^https?://#', '', $nft_checkout_target_host);
    $nft_checkout_parsed_host = rtrim($nft_checkout_parsed_host, '/');
    define('NFT_CHECKOUT_BASE_URL', NFT_CHECKOUT_PROTOCOL . $nft_checkout_parsed_host);
}
 
if (!defined('NFT_CHECKOUT_PLUGIN_VERSION')) {
    define('NFT_CHECKOUT_PLUGIN_VERSION', '1.0.0');
}