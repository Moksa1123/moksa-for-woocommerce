<?php
/**
 * moksa-store-closed plain-text email.
 */
defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- mo_ is plugin owner prefix per CLAUDE.md.
$mo_store_name = (string) $order->get_meta( '_moksafowo_shipping_cvs_store_name' );

echo "= " . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: %s: customer first name */
echo sprintf( esc_html__( 'Hi %s,', 'moksa-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
echo sprintf(
	/* translators: %s: convenience store name */
	esc_html__( 'The pickup store you chose (%s) is temporarily closed for renovation, relocation or a change of store number. Please choose another pickup store as soon as possible.', 'moksa-for-woocommerce' ),
	esc_html( $mo_store_name ?: __( 'Unknown store', 'moksa-for-woocommerce' ) )
) . "\n\n";
echo esc_html__( 'If no new store is chosen in time, the parcel will be returned to the distribution center. Go to My account > Orders, open this order and choose another store, or contact us for help.', 'moksa-for-woocommerce' ) . "\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
echo "\n----------------------------------------\n\n";
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_strip_all_tags returns plain text safe for plaintext email body.
echo "\n\n" . wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
