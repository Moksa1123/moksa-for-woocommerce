<?php
/**
 * moksa-shipped plain-text email — 已出貨通知。
 */

defined( 'ABSPATH' ) || exit;

echo "= " . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: %s: customer first name */
echo sprintf( esc_html__( 'Hi %s,', 'moksa-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
echo esc_html__( 'Your order has shipped and is on its way. The tracking number and shipping status are below.', 'moksa-for-woocommerce' ) . "\n\n";
// 訪客結帳沒有帳號，不提帳號頁。
if ( $order->get_customer_id() > 0 ) {
	/* translators: %s: link to the order in the customer account */
	echo esc_html( sprintf( __( 'You can also check the shipping status in your account: %s', 'moksa-for-woocommerce' ), $order->get_view_order_url() ) ) . "\n\n";
}

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
echo "\n----------------------------------------\n\n";
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
do_action( 'moksafowo_shipping_email_tracking_info', $order, true );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

// 商家在信件設定頁填的「額外內容」。核心的純文字範本都印這一段。
if ( ! empty( $additional_content ) ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
	echo "\n----------------------------------------\n";
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_strip_all_tags returns plain text safe for plaintext email body.
echo "\n\n" . wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
