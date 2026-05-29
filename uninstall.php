<?php
/**
 * Uninstall MoWP. Removes all plugin options and meta keys.
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

/* Remove all mo_* options and transients. */
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mo\_%' OR option_name LIKE '\_transient\_mo\_%' OR option_name LIKE '\_transient\_timeout\_mo\_%'"
);

/* Remove all mowp-prefixed gateway settings. */
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce\_mo\_%\_settings'"
);

/*
 * Fork modules carry option keys that don't share the `mo_` prefix
 * (`Mo_LinePay_*`, `payuni_payment_*`). Listed explicitly so the cleanup
 * is correct on case-sensitive collations too.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- mo_ is plugin owner prefix per CLAUDE.md.
$mo_fork_options = array(
	'Mo_LinePay_channel_id',
	'Mo_LinePay_channel_secret',
	'Mo_LinePay_sandbox_channel_id',
	'Mo_LinePay_sandbox_channel_secret',
	'Mo_LinePay_sandboxmode_enabled',
	'Mo_LinePay_payment_fail_order_status',
	'Mo_LinePay_detail_status_note_enabled',
	'Mo_LinePay_debug_log_enabled',
	'Mo_LinePay_display_logo_enabled',
	'payuni_payment_hashkey',
	'payuni_payment_hashkey_test',
	'payuni_payment_hashiv',
	'payuni_payment_hashiv_test',
	'payuni_payment_merchant_id',
	'payuni_payment_merchant_id_test',
	'payuni_payment_testmode_enabled',
	'payuni_payment_einvoice_enabled',
	'payuni_payment_debug_log_enabled',
	'payuni_payment_installment_number_of_payments',
	'payuni_payment_language',
);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- mo_ is plugin owner prefix per CLAUDE.md.
foreach ( $mo_fork_options as $mo_opt ) {
	delete_option( $mo_opt );
}

/* Fork gateway settings stored under WC's own prefix. */
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce\_linepay-tw\_settings' OR option_name LIKE 'woocommerce\_payuni-%'"
);

/* Remove all _mo_* postmeta (legacy CPT orders). */
$wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_mo\_%'"
);

/* Remove all _mo_* HPOS order meta if HPOS table exists. */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- mo_ is plugin owner prefix per CLAUDE.md.
$mo_hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- mo_ is plugin owner prefix per CLAUDE.md.
$mo_exists          = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mo_hpos_meta_table ) );
if ( $mo_hpos_meta_table === $mo_exists ) {
	$wpdb->query( 'DELETE FROM `' . esc_sql( $mo_hpos_meta_table ) . "` WHERE meta_key LIKE '\_mo\_%'" );
}

/* Remove user meta. */
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'mo\_%'" );

/* Clear scheduled actions. */
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', [], 'mo-ectools' );
}
