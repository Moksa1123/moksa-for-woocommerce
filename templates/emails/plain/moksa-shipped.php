<?php
/**
 * moksa-shipped plain-text email — 已出貨通知。
 */

defined( 'ABSPATH' ) || exit;

echo "= " . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: %s: customer first name */
echo sprintf( esc_html__( '%s 您好，', 'mo-ectools' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
echo esc_html__( '您的訂單已出貨，物流商正在處理運送中。', 'mo-ectools' ) . "\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
echo "\n----------------------------------------\n\n";
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- mo_ is plugin owner prefix per CLAUDE.md.
do_action( 'moksafowo_shipping_email_tracking_info', $order, true );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_strip_all_tags returns plain text safe for plaintext email body.
echo "\n\n" . wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
