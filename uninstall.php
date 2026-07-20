<?php
/**
 * Uninstall Moksa for WooCommerce. Removes all plugin options and meta keys.
 *
 * @package Moksafowo
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Uninstall runs outside the object cache and has to touch tables the WP API does not expose
 * (postmeta / usermeta / HPOS meta by pattern, plus our own tables). Every table name goes through
 * prepare()'s %i identifier placeholder and every LIKE pattern through %s — nothing is interpolated.
 */

// Remove all moksafowo_* options and transients (incl. legacy mo_* from pre-1.0 builds).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s
			OR option_name LIKE %s
			OR option_name LIKE %s
			OR option_name LIKE %s
			OR option_name LIKE %s
			OR option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( 'moksafowo_' ) . '%',
		$wpdb->esc_like( '_transient_moksafowo_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_moksafowo_' ) . '%',
		$wpdb->esc_like( 'mo_' ) . '%',
		$wpdb->esc_like( '_transient_mo_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mo_' ) . '%'
	)
);

// Remove gateway settings stored under WC's own woocommerce_{gateway_id}_settings convention.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s
			OR option_name LIKE %s
			OR option_name LIKE %s
			OR option_name = %s
			OR option_name = %s
			OR option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( 'woocommerce_moksafowo_' ) . '%' . $wpdb->esc_like( '_settings' ),
		$wpdb->esc_like( 'woocommerce_moksafowo-' ) . '%' . $wpdb->esc_like( '_settings' ),
		$wpdb->esc_like( 'woocommerce_mo_' ) . '%' . $wpdb->esc_like( '_settings' ),
		'woocommerce_linepay-tw_settings',
		'woocommerce_payuni_settings',
		$wpdb->esc_like( 'woocommerce_payuni-' ) . '%' . $wpdb->esc_like( '_settings' )
	)
);

/*
 * Mixed-case fork option keys (`Moksafowo_LinePay_*`) plus legacy spellings.
 * Listed explicitly so the cleanup is correct on case-sensitive collations too.
 */
$moksafowo_fork_options = array(
	'Moksafowo_LinePay_channel_id',
	'Moksafowo_LinePay_channel_secret',
	'Moksafowo_LinePay_sandbox_channel_id',
	'Moksafowo_LinePay_sandbox_channel_secret',
	'Moksafowo_LinePay_sandboxmode_enabled',
	'Moksafowo_LinePay_payment_fail_order_status',
	'Moksafowo_LinePay_detail_status_note_enabled',
	'Moksafowo_LinePay_debug_log_enabled',
	'Moksafowo_LinePay_display_logo_enabled',
);
foreach ( $moksafowo_fork_options as $moksafowo_opt ) {
	delete_option( $moksafowo_opt );
	delete_option( str_replace( 'Moksafowo_', 'Mo_', $moksafowo_opt ) ); // legacy
}

// Legacy payuni_* options from pre-1.0 builds.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( 'payuni_payment_' ) . '%',
		$wpdb->esc_like( 'moksafowo_payuni_' ) . '%'
	)
);

// Remove all plugin postmeta (legacy CPT orders).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s',
		$wpdb->postmeta,
		$wpdb->esc_like( '_moksafowo_' ) . '%',
		$wpdb->esc_like( '_mo_' ) . '%',
		$wpdb->esc_like( '_linepay_' ) . '%'
	)
);

// Remove all plugin HPOS order meta if HPOS table exists.
$moksafowo_hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$moksafowo_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $moksafowo_hpos_meta_table ) );
if ( $moksafowo_hpos_meta_table === $moksafowo_table_exists ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s',
			$moksafowo_hpos_meta_table,
			$wpdb->esc_like( '_moksafowo_' ) . '%',
			$wpdb->esc_like( '_mo_' ) . '%',
			$wpdb->esc_like( '_linepay_' ) . '%'
		)
	);
}

// Remove user meta.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE meta_key LIKE %s OR meta_key LIKE %s',
		$wpdb->usermeta,
		$wpdb->esc_like( 'moksafowo_' ) . '%',
		$wpdb->esc_like( 'mo_' ) . '%'
	)
);

// Drop the order-number lookup index table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'moksafowo_order_lookup' ) );

// Drop the customer-service threads / messages tables.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'moksafowo_cs_messages' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'moksafowo_cs_threads' ) );

// Clear scheduled actions.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'moksa-for-woocommerce' );
}
