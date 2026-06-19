<?php
/**
 * Uninstall Moksa for WooCommerce. Removes all plugin options and meta keys.
 *
 * @package MoksaWeb\Mowc
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Uninstall context: direct $wpdb queries are canonical (object cache not bootstrapped, schema reads required).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange

global $wpdb;

/* Remove all moksafowo_* options and transients (incl. legacy mo_* from pre-1.0 builds). */
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'moksafowo\_%'
		OR option_name LIKE '\_transient\_moksafowo\_%'
		OR option_name LIKE '\_transient\_timeout\_moksafowo\_%'
		OR option_name LIKE 'mo\_%'
		OR option_name LIKE '\_transient\_mo\_%'
		OR option_name LIKE '\_transient\_timeout\_mo\_%'"
);

/* Remove gateway settings stored under WC's own woocommerce_{gateway_id}_settings convention. */
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce\_moksafowo\_%\_settings'
		OR option_name LIKE 'woocommerce\_moksafowo-%\_settings'
		OR option_name LIKE 'woocommerce\_mo\_%\_settings'
		OR option_name = 'woocommerce_linepay-tw_settings'
		OR option_name = 'woocommerce_payuni_settings'
		OR option_name LIKE 'woocommerce\_payuni-%\_settings'"
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

/* Legacy payuni_* options from pre-1.0 builds. */
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'payuni\_payment\_%' OR option_name LIKE 'moksafowo\_payuni\_%'" );

/* Remove all plugin postmeta (legacy CPT orders). */
$wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_moksafowo\_%' OR meta_key LIKE '\_mo\_%' OR meta_key LIKE '\_linepay\_%'"
);

/* Remove all plugin HPOS order meta if HPOS table exists. */
$moksafowo_hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
$moksafowo_table_exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $moksafowo_hpos_meta_table ) );
if ( $moksafowo_hpos_meta_table === $moksafowo_table_exists ) {
	$wpdb->query( 'DELETE FROM `' . esc_sql( $moksafowo_hpos_meta_table ) . "` WHERE meta_key LIKE '\_moksafowo\_%' OR meta_key LIKE '\_mo\_%' OR meta_key LIKE '\_linepay\_%'" );
}

/* Remove user meta. */
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'moksafowo\_%' OR meta_key LIKE 'mo\_%'" );

/* Drop the order-number lookup index table. */
$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'moksafowo_order_lookup' ) . '`' );

/* Drop the customer-service threads / messages tables. */
$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'moksafowo_cs_messages' ) . '`' );
$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'moksafowo_cs_threads' ) . '`' );

/* Clear scheduled actions. */
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'mo-ectools' );
}
