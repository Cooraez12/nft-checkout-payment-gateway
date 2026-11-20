<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes this plugin's options, lock options, transients, cron events, its
 * own payment-link table and the meta it wrote on orders. Nothing that belongs
 * to another plugin is touched.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

function rtnfch_uninstall() {
	global $wpdb;

	wp_clear_scheduled_hook('rtnfch_cron_event');
	wp_clear_scheduled_hook('rtnfch_async_sync_order');

	$rtnfch_options = [
		'woocommerce_rt-nft-checkout_settings',
		'rtnfch_accounts',
		'rtnfch_group_id',
		'rtnfch_plugin_version',
		'rtnfch_schema_version',
		'rtnfch_legacy_rows_migrated',
	];
	foreach ($rtnfch_options as $rtnfch_option) {
		delete_option($rtnfch_option);
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

	// This plugin's lock options and transients.
	$patterns = [
		$wpdb->esc_like('rtnfch_lock_') . '%',
		$wpdb->esc_like('_transient_rtnfch_') . '%',
		$wpdb->esc_like('_transient_timeout_rtnfch_') . '%',
	];
	foreach ($patterns as $pattern) {
		$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $pattern));
	}

	// This plugin's own payment-link table.
	$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'rtnfch_payment_link'));

	// Order meta written by this plugin (posts storage and HPOS storage).
	$meta_like = $wpdb->esc_like('_rtnfch_') . '%';
	$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, $meta_like));
	$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
	if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_meta_table)) === $hpos_meta_table) {
		$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE meta_key LIKE %s', $hpos_meta_table, $meta_like));
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}
rtnfch_uninstall();
