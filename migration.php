<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Schema version written once every step below has run. Bump it whenever a
 * step is added; the main plugin file runs the steps when the stored version
 * is older.
 */
define('RTNFCH_SCHEMA_VERSION', '1.0.5');

/**
 * NFT-13: copy this gateway's rows out of the table the pre-1.0.2 plugin
 * shared with the bank transfer gateway, once. That table is left untouched
 * for the other plugin.
 */
function rtnfch_migrate_legacy_rows()
{
	global $wpdb;

	if (get_option('rtnfch_legacy_rows_migrated')) {
		return;
	}

	$legacy_table = $wpdb->prefix . 'order_payment_link';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy_table));
	if ($exists === $legacy_table && function_exists('wc_get_order')) {
		$rows = $wpdb->get_results(
			$wpdb->prepare('SELECT order_id, uuid, payment_link, customer_email, amount, created_at FROM %i', $legacy_table),
			ARRAY_A
		);
		foreach ((array) $rows as $row) {
			$order = wc_get_order((int) ($row['order_id'] ?? 0));
			if (!rtnfch_is_gateway_order($order) || empty($row['uuid'])) {
				continue;
			}
			rtnfch_store_order_payment_link($order, [
				'uuid'           => (string) $row['uuid'],
				'payment_link'   => (string) ($row['payment_link'] ?? ''),
				'customer_email' => (string) ($row['customer_email'] ?? ''),
				'amount'         => $row['amount'] ?? $order->get_total(),
			]);
		}
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	update_option('rtnfch_legacy_rows_migrated', '1');
}

/**
 * Normalise this plugin's own settings. NFT-13: nothing is read from, or
 * copied out of, an older plugin's options any more; a
 * merchant who used that plugin enters their RT keys here themselves.
 */
function rtnfch_migrate_old_settings()
{
	rtnfch_clean_legacy_settings_keys();

	$accounts = get_option(RTNFCH_ACCOUNTS_OPTION);
	if (!$accounts) {
		return;
	}
	if (is_string($accounts)) {
		$accounts = maybe_unserialize($accounts);
	}
	if (!is_array($accounts) || empty($accounts)) {
		return;
	}

	$enhanced_accounts = array_map(function ($account) {
		if (!is_array($account)) {
			return $account;
		}
		$account['live_status']    = $account['live_status'] ?? 'active';
		$account['sandbox_status'] = $account['sandbox_status'] ?? 'active';
		$account['has_sandbox']    = (!empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) ? 'on' : 'off';
		$account['priority']       = $account['priority'] ?? 1;
		$account['title']          = $account['title'] ?? 'Default Account';
		if (empty($account['unique_id'])) {
			$account['unique_id'] = 'acc_' . wp_generate_uuid4();
		}
		return $account;
	}, array_values(array_filter($accounts, 'is_array')));

	update_option(RTNFCH_ACCOUNTS_OPTION, $enhanced_accounts);
}

/**
 * Remove key material from the gateway settings option: keys live in the
 * accounts option only.
 */
function rtnfch_clean_legacy_settings_keys()
{
	foreach ([RTNFCH_SETTINGS_OPTION] as $opt_name) {
		$settings = get_option($opt_name);
		if (is_array($settings)) {
			$cleaned = false;
			foreach (['public_key', 'secret_key', 'sandbox_public_key', 'sandbox_secret_key'] as $key) {
				if (isset($settings[$key])) {
					unset($settings[$key]);
					$cleaned = true;
				}
			}
			if ($cleaned) {
				update_option($opt_name, $settings);
			}
		}
	}
}

/**
 * 1.0.5 renamed every stored name to the plugin's own "rtnfch" prefix. Move a
 * store's existing settings, order data and payment-link rows to the new
 * names once, so an update keeps working. Old names are only read and removed
 * here; nothing is written under them.
 */
function rtnfch_migrate_pre_105_names()
{
	global $wpdb;

	$option_moves = [
		'woocommerce_nft-checkout_settings'                 => RTNFCH_SETTINGS_OPTION,
		'woocommerce_nft-checkout_payment_gateway_accounts' => RTNFCH_ACCOUNTS_OPTION,
		'nft_checkout_group_id'                             => 'rtnfch_group_id',
		'nft_checkout_legacy_rows_migrated'                 => 'rtnfch_legacy_rows_migrated',
	];
	foreach ($option_moves as $old_name => $new_name) {
		$old_value = get_option($old_name, null);
		if ($old_value === null) {
			continue;
		}
		if (get_option($new_name, null) === null) {
			update_option($new_name, $old_value, false);
		}
		delete_option($old_name);
	}
	delete_option('nft_checkout_schema_version');
	delete_option('nft_checkout_plugin_version');

	foreach (['rt_nft_checkout_cron_event', 'rt_nft_checkout_async_sync_order', 'nft_checkout_cron_event'] as $old_hook) {
		wp_clear_scheduled_hook($old_hook);
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$old_meta_like = $wpdb->esc_like('_nft_checkout_') . '%';
	$new_meta_like = $wpdb->esc_like('_rtnfch_') . '%';
	$meta_tables   = [[$wpdb->postmeta, 'post_id']];
	$hpos_meta     = $wpdb->prefix . 'wc_orders_meta';
	if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_meta)) === $hpos_meta) {
		$meta_tables[] = [$hpos_meta, 'order_id'];
	}
	foreach ($meta_tables as [$meta_table, $id_column]) {
		$wpdb->query($wpdb->prepare(
			'UPDATE %i SET meta_key = CONCAT(%s, SUBSTRING(meta_key, 15)) WHERE meta_key LIKE %s',
			$meta_table,
			'_rtnfch_',
			$old_meta_like
		));
		// The test-order flag had no prefix: move it only on this plugin's orders.
		$wpdb->query($wpdb->prepare(
			'UPDATE %i AS a INNER JOIN %i AS b ON a.%i = b.%i AND b.meta_key LIKE %s SET a.meta_key = %s WHERE a.meta_key = %s',
			$meta_table,
			$meta_table,
			$id_column,
			$id_column,
			$new_meta_like,
			'_rtnfch_is_test_order',
			'_is_test_order'
		));
	}

	$old_table = $wpdb->prefix . 'rt_nft_checkout_payment_link';
	$new_table = rtnfch_table_name();
	if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $old_table)) === $old_table && rtnfch_order_payment_link_table_exists()) {
		$old_rows = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $old_table));
		$copied   = 0;
		if ($old_rows > 0) {
			$copied = $wpdb->query($wpdb->prepare(
				'INSERT INTO %i (order_id, uuid, payment_link, customer_email, amount, created_at) SELECT order_id, uuid, payment_link, customer_email, amount, created_at FROM %i',
				$new_table,
				$old_table
			));
		}
		// Remove the old table only when every row reached the new one.
		if ((int) $copied === $old_rows) {
			$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $old_table));
		}
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

/**
 * Run every schema step the stored version has not seen yet. Nothing here
 * contacts RT or prints anything.
 */
function rtnfch_run_schema_steps()
{
	rtnfch_create_tables();
	rtnfch_migrate_pre_105_names();
	rtnfch_migrate_legacy_rows();
	rtnfch_migrate_old_settings();

	if (class_exists('RTNFCH_GATEWAY_Loader')) {
		RTNFCH_GATEWAY_Loader::get_instance()->rtnfch_activate_cron_job();
	}

	update_option('rtnfch_schema_version', RTNFCH_SCHEMA_VERSION);
	update_option('rtnfch_plugin_version', RTNFCH_PLUGIN_VERSION);
}

function rtnfch_maybe_upgrade()
{
	$current_schema = get_option('rtnfch_schema_version', '0');
	if (version_compare($current_schema, RTNFCH_SCHEMA_VERSION, '>=')) {
		return;
	}
	rtnfch_run_schema_steps();
}

/**
 * Activation only prepares local data. It never contacts RT.
 */
function rtnfch_on_plugin_activate() {
	rtnfch_run_schema_steps();
}

function rtnfch_on_plugin_deactivate() {
	if (class_exists('RTNFCH_GATEWAY_Loader')) {
		RTNFCH_GATEWAY_Loader::get_instance()->rtnfch_deactivate_cron_job();
		// Sent only when the merchant opted in to sharing plugin status and has keys.
		RTNFCH_GATEWAY_Loader::get_instance()->rtnfch_send_plugin_status(0, 0);
	}
}

register_activation_hook(RTNFCH_GATEWAY_FILE, 'rtnfch_on_plugin_activate');
register_deactivation_hook(RTNFCH_GATEWAY_FILE, 'rtnfch_on_plugin_deactivate');
