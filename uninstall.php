<?php
/**
 * Uninstall Moksa for WooCommerce. Removes the plugin's settings; keeps order data.
 *
 * @package Moksafowo
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * Settings: every option this plugin has written is recorded by Settings\OptionRegistry
 * (via the added_option hook, plus a one-time backfill on upgrade), so they are deleted one by one
 * through the options API. WordPress has no API for deleting options by prefix.
 * Transients are not recorded; they expire on their own.
 */
$moksafowo_registry = get_option( 'moksafowo_option_registry', array() );
if ( is_array( $moksafowo_registry ) ) {
	foreach ( $moksafowo_registry as $moksafowo_option ) {
		if ( is_string( $moksafowo_option ) && '' !== $moksafowo_option ) {
			delete_option( $moksafowo_option );
		}
	}
}
delete_option( 'moksafowo_option_registry' );

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

/*
 * Customer-service threads (private post type) and their messages (comments). wp_delete_post()
 * with force removes the comments too. The post type and its custom statuses must be registered
 * first, otherwise WP_Query drops the unregistered statuses and finds nothing.
 */
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}
if ( class_exists( '\Moksafowo\Modules\CustomerService\ThreadPosts' ) ) {
	\Moksafowo\Modules\CustomerService\ThreadPosts::register();
	$moksafowo_seen = array();
	do {
		$moksafowo_threads = get_posts(
			array(
				'post_type'      => \Moksafowo\Modules\CustomerService\ThreadPosts::POST_TYPE,
				'post_status'    => array( 'moksafowo-open', 'moksafowo-answered', 'moksafowo-closed', 'trash' ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		// 刪不掉的（被別的外掛擋下）會一直被查回來：同一批又出現就停，避免跑到逾時。
		$moksafowo_new = array_diff( array_map( 'intval', $moksafowo_threads ), $moksafowo_seen );
		foreach ( $moksafowo_new as $moksafowo_thread_id ) {
			wp_delete_post( (int) $moksafowo_thread_id, true );
		}
		$moksafowo_seen = array_merge( $moksafowo_seen, $moksafowo_new );
	} while ( ! empty( $moksafowo_new ) );
}

// Order-number lookup index (private taxonomy): the terms are a search index, rebuilt from orders.
if ( class_exists( '\Moksafowo\Modules\OrderLookup\Index\TermIndex' ) ) {
	\Moksafowo\Modules\OrderLookup\Index\TermIndex::register();
	$moksafowo_seen = array();
	do {
		$moksafowo_terms = get_terms(
			array(
				'taxonomy'   => \Moksafowo\Modules\OrderLookup\Index\TermIndex::TAXONOMY,
				'hide_empty' => false,
				'number'     => 200,
				'fields'     => 'ids',
			)
		);
		$moksafowo_terms = is_array( $moksafowo_terms ) ? $moksafowo_terms : array();
		$moksafowo_new   = array_diff( array_map( 'intval', $moksafowo_terms ), $moksafowo_seen );
		foreach ( $moksafowo_new as $moksafowo_term_id ) {
			wp_delete_term( (int) $moksafowo_term_id, \Moksafowo\Modules\OrderLookup\Index\TermIndex::TAXONOMY );
		}
		$moksafowo_seen = array_merge( $moksafowo_seen, $moksafowo_new );
	} while ( ! empty( $moksafowo_new ) );
}

// Saved-card member IDs stored on customers.
delete_metadata( 'user', 0, '_moksafowo_payment_member_id', '', true );

/*
 * Order data is kept on purpose (store owner's decision, 2026-11-15): payment transaction IDs,
 * e-invoice numbers, tracking numbers and pickup stores on orders are the store's trading records
 * and must survive an accidental uninstall.
 *
 * The saved card credentials that could charge the customer again (Order\Meta\Keys::PAYMENT_CREDENTIALS)
 * are removed, through the WooCommerce order API, in batches. Without the plugin they have no use.
 */
if ( function_exists( 'wc_get_orders' ) && class_exists( '\Moksafowo\Order\Lookup' ) ) {
	foreach ( \Moksafowo\Order\Meta\Keys::PAYMENT_CREDENTIALS as $moksafowo_credential ) {
		$moksafowo_seen = array();
		do {
			$moksafowo_ids = \Moksafowo\Order\Lookup::orders(
				array(
					'type'                             => wc_get_order_types(),
					'status'                           => 'all',
					'limit'                            => 100,
					'return'                           => 'ids',
					\Moksafowo\Order\Lookup::QUERY_VAR => array(
						'key'     => $moksafowo_credential,
						'compare' => 'EXISTS',
					),
				)
			);
			$moksafowo_new = array_diff( array_map( 'intval', $moksafowo_ids ), $moksafowo_seen );
			foreach ( $moksafowo_new as $moksafowo_order_id ) {
				$moksafowo_order = wc_get_order( $moksafowo_order_id );
				if ( $moksafowo_order ) {
					$moksafowo_order->delete_meta_data( $moksafowo_credential );
					$moksafowo_order->save();
				}
			}
			$moksafowo_seen = array_merge( $moksafowo_seen, $moksafowo_new );
		} while ( ! empty( $moksafowo_new ) );
	}
}

// Drop the order-number lookup index table and the customer-service tables.
// These tables are being replaced by WordPress data types; this block goes away once that is done.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'moksafowo_order_lookup' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'moksafowo_cs_messages' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'moksafowo_cs_threads' ) );

// Clear scheduled actions.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'moksa-for-woocommerce' );
}
