<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
 
// config.php
if (!defined('NFT_CHECKOUT_PROTOCOL')) {
    define('NFT_CHECKOUT_PROTOCOL', is_ssl() ? 'https://' : 'http://');
}
 
if (!defined('NFT_CHECKOUT_HOST')) {
    define('NFT_CHECKOUT_HOST', 'rt.app');
}
 
if (!defined('NFT_CHECKOUT_BASE_URL')) {
    define('NFT_CHECKOUT_BASE_URL', NFT_CHECKOUT_PROTOCOL . NFT_CHECKOUT_HOST);
}
 
if (!defined('NFT_CHECKOUT_PLUGIN_VERSION')) {
    define('NFT_CHECKOUT_PLUGIN_VERSION', '1.0.0');
}
