<?php
/**
 * mo-cvs-arrived plain-text email — 包裹到店待取通知。
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- mo_ is plugin owner prefix per CLAUDE.md.
$mo_store_name    = (string) $order->get_meta( '_mo_shipping_cvs_store_name' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- mo_ is plugin owner prefix per CLAUDE.md.
$mo_store_id      = (string) $order->get_meta( '_mo_shipping_cvs_store_id' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- mo_ is plugin owner prefix per CLAUDE.md.
$mo_store_address = (string) $order->get_meta( '_mo_shipping_cvs_store_address' );

echo "= " . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: %s: customer first name */
echo sprintf( esc_html__( '%s 您好，', 'mo-ectools' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
echo esc_html__( '您的包裹已送達取件門市，請於 7 天內持證件至門市取貨。', 'mo-ectools' ) . "\n\n";

if ( $mo_store_name ) {
	echo esc_html__( '取件門市', 'mo-ectools' ) . ': ' . esc_html( $mo_store_name ) . "\n";
}
if ( $mo_store_id ) {
	echo esc_html__( '門市代號', 'mo-ectools' ) . ': ' . esc_html( $mo_store_id ) . "\n";
}
if ( $mo_store_address ) {
	echo esc_html__( '門市地址', 'mo-ectools' ) . ': ' . esc_html( $mo_store_address ) . "\n";
}
echo "\n";
echo esc_html__( '逾期未取件，包裹將退回物流中心，並可能產生退運費用。', 'mo-ectools' ) . "\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
echo "\n----------------------------------------\n\n";
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
do_action( 'mo_shipping_email_tracking_info', $order, true );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_strip_all_tags returns plain text safe for plaintext email body.
echo "\n\n" . wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
